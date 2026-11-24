<?php

namespace App\Domain\Knowledge;

use App\Domain\Telegram\PersonalLink;
use App\Models\{TrainingAssignment, TrainingEnrollment, User};
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Programs assigned with a deadline, and the daily Telegram reminders:
 * a deadline in 2 days, an overdue deadline (to the employee, and once to whoever assigned it),
 * and more than 3 days without any training activity while a program is unfinished.
 */
final class TrainingAssignments
{
    public const TIMEZONE = 'Asia/Tashkent';

    public const INACTIVE_DAYS = 3;

    public const DUE_SOON_DAYS = 2;

    public function __construct(private Training $training, private PersonalLink $telegram) {}

    public function assign(User $by, User $member, string $trackId, string $dueOn, ?string $note): TrainingAssignment
    {
        if (! $this->training->manages($by, $member)) {
            abort(403);
        }
        $track = collect($this->training->tracksFor($member->role))->firstWhere('id', $trackId);
        if (! $track) {
            throw ValidationException::withMessages(['track_id' => 'Эта программа недоступна для роли сотрудника.']);
        }
        if (CarbonImmutable::parse($dueOn, self::TIMEZONE)->lt($this->today())) {
            throw ValidationException::withMessages(['due_on' => 'Срок не может быть в прошлом.']);
        }
        $assignment = TrainingAssignment::updateOrCreate(['user_id' => $member->id, 'track_id' => $trackId],
            ['assigned_by' => $by->id, 'due_on' => $dueOn, 'note' => $note !== null && trim($note) !== '' ? trim($note) : null]);
        $this->telegram->send($member, "📚 {$by->name} назначил(а) вам программу «{$track['title']}».\nСрок: до ".$assignment->due_on->format('d.m.Y').'.'
            .($assignment->note ? "\nКомментарий: {$assignment->note}" : '')."\n\n".$this->url());

        return $assignment;
    }

    public function remove(User $by, TrainingAssignment $assignment): void
    {
        if (! $this->training->manages($by, $assignment->user)) {
            abort(403);
        }
        $assignment->delete();
    }

    /** Assignments of these users: [user_id => [track_id => state]]. */
    public function states(Collection $userIds): array
    {
        $rows = TrainingAssignment::with('assigner:id,name')->whereIn('user_id', $userIds)->get();
        $done = TrainingEnrollment::whereIn('user_id', $userIds)->whereNotNull('completed_at')->get()->keyBy(fn ($e) => $e->user_id.':'.$e->track_id);
        $today = $this->today();
        $out = [];
        foreach ($rows as $a) {
            $completed = $done->get($a->user_id.':'.$a->track_id)?->completed_at;
            $due = CarbonImmutable::parse($a->due_on->toDateString(), self::TIMEZONE);
            $out[$a->user_id][$a->track_id] = [
                'id' => $a->id, 'due_on' => $a->due_on->toDateString(), 'note' => $a->note, 'assigned_by' => $a->assigner?->name,
                'completed_at' => $completed?->toIso8601String(),
                'on_time' => $completed ? $completed->copy()->tz(self::TIMEZONE)->toDateString() <= $a->due_on->toDateString() : null,
                'days_left' => $completed ? null : (int) $today->diffInDays($due, false),
                'overdue' => ! $completed && $due->lt($today),
            ];
        }

        return $out;
    }

    public function forUser(User $user): array
    {
        return $this->states(collect([$user->id]))[$user->id] ?? [];
    }

    /** Daily sweep. Returns the number of reminders sent. */
    public function remind(): int
    {
        $today = $this->today();
        $sent = 0;
        $users = User::where('active', true)->whereIn('id', DB::table('telegram_user_links')->select('user_id'))->get();
        if ($users->isEmpty()) {
            return 0;
        }
        $ids = $users->pluck('id');
        $states = $this->states($ids);
        $open = TrainingEnrollment::whereIn('user_id', $ids)->whereNull('completed_at')->get()->groupBy('user_id');
        $activity = $this->lastActivity($ids);
        $titles = collect($this->training->tracks())->pluck('title', 'id');

        foreach ($users as $user) {
            foreach ($states[$user->id] ?? [] as $trackId => $a) {
                if ($a['completed_at']) {
                    continue;
                }
                $title = $titles[$trackId] ?? $trackId;
                $due = CarbonImmutable::parse($a['due_on'])->format('d.m.Y');
                if (! $a['overdue'] && $a['days_left'] <= self::DUE_SOON_DAYS) {
                    $sent += $this->once($user, 'due_soon', 'a'.$a['id'].':'.$a['due_on'].':soon',
                        "⏰ Срок по программе «{$title}» — {$due}".($a['days_left'] === 0 ? ' (сегодня)' : ", осталось дней: {$a['days_left']}").".\nДопройдите главы и сдайте экзамен.\n\n".$this->url());
                } elseif ($a['overdue']) {
                    $late = -$a['days_left'];
                    $sent += $this->once($user, 'overdue', 'a'.$a['id'].':'.$a['due_on'].':late:'.intdiv($late - 1, self::INACTIVE_DAYS),
                        "❗ Срок по программе «{$title}» прошёл {$due} (просрочка {$late} дн.).\nПродолжите обучение и сообщите руководителю, когда планируете закончить.\n\n".$this->url());
                    $assigner = TrainingAssignment::find($a['id'])?->assigner;
                    if ($assigner && $assigner->active) {
                        $sent += $this->once($assigner, 'overdue_lead', 'a'.$a['id'].':'.$a['due_on'].':lead',
                            "❗ {$user->name} не прошёл(а) программу «{$title}» к сроку {$due}.\n\n".$this->url());
                    }
                }
            }
            $unfinished = collect($states[$user->id] ?? [])->contains(fn ($a) => ! $a['completed_at']) || ($open[$user->id] ?? collect())->isNotEmpty();
            $last = $activity[$user->id] ?? null;
            if ($unfinished && $last) {
                $idle = (int) $last->tz(self::TIMEZONE)->startOfDay()->diffInDays($today);
                if ($idle >= self::INACTIVE_DAYS) {
                    $sent += $this->once($user, 'inactive', 'u'.$user->id.':idle:'.$last->toDateString().':'.intdiv($idle, self::INACTIVE_DAYS),
                        "👋 Вы не занимались обучением {$idle} дн. Продолжите с того места, где остановились — это займёт 15–20 минут.\n\n".$this->url());
                }
            }
        }

        return $sent;
    }

    /** @return array<int, CarbonImmutable> */
    private function lastActivity(Collection $ids): array
    {
        $sources = [
            ['knowledge_progress', 'updated_at'], ['quiz_attempts', 'updated_at'], ['training_enrollments', 'updated_at'],
            ['training_ticket_runs', 'updated_at'], ['training_lab_attempts', 'updated_at'], ['training_lab_submissions', 'updated_at'],
        ];
        $out = [];
        foreach ($sources as [$table, $column]) {
            foreach (DB::table($table)->whereIn('user_id', $ids)->groupBy('user_id')->selectRaw("user_id, max($column) as at")->get() as $row) {
                if ($row->at) {
                    $at = CarbonImmutable::parse($row->at, 'UTC');
                    $out[$row->user_id] = isset($out[$row->user_id]) && $out[$row->user_id]->gt($at) ? $out[$row->user_id] : $at;
                }
            }
        }

        return $out;
    }

    private function once(User $user, string $kind, string $key, string $text): int
    {
        $inserted = DB::table('training_reminders')->insertOrIgnore(['user_id' => $user->id, 'kind' => $kind, 'key' => $key, 'sent_at' => now()]);
        if (! $inserted) {
            return 0;
        }
        $ok = $this->telegram->send($user, $text);
        DB::table('training_reminders')->where('key', $key)->update(['delivered' => $ok]);

        return $ok ? 1 : 0;
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }

    private function url(): string
    {
        return rtrim((string) config('app.url'), '/').'/training';
    }
}
