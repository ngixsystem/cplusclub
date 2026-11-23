<?php

namespace App\Domain\Training;

use App\Domain\Knowledge\Library;
use App\Models\{Ticket, TrainingRun, User};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ticket practice: a simulated club incident is opened as a real ticket in the hidden training club
 * and assigned to the trainee. He works it through the normal ticket page — accept, start work,
 * diagnose, propose paid work when needed, fill the result and resolve. A simulated club client
 * approves proposals and accepts the result (or returns it once if the check is not described).
 * When the ticket closes the run is graded out of 100 with a breakdown and a model answer.
 */
final class Practice
{
    public const PASS_SCORE = 70;

    private ?array $scenarios = null;

    public function __construct(private readonly TrainingSandbox $sandbox, private readonly Library $library) {}

    public function scenarios(): array
    {
        return $this->scenarios ??= collect(json_decode(file_get_contents(resource_path('knowledge/scenarios.json')), true, 512, JSON_THROW_ON_ERROR))
            ->keyBy('id')->all();
    }

    public function scenario(string $id): ?array
    {
        return $this->scenarios()[$id] ?? null;
    }

    /** Scenario ids the user's role may practise (from the practice block of his programs). */
    public function allowedFor(User $user, array $tracks): array
    {
        return collect($tracks)->pluck('practice.scenarios')->filter()->flatten()->unique()->values()->all();
    }

    // ---- Run lifecycle --------------------------------------------------------------------------

    public function start(User $user, string $scenarioId): TrainingRun
    {
        $scenario = $this->scenario($scenarioId);
        abort_unless($scenario, 404);
        $active = TrainingRun::where('user_id', $user->id)->where('scenario_id', $scenarioId)->where('status', 'active')->first();
        if ($active) {
            return $active;
        }

        return DB::transaction(function () use ($user, $scenario) {
            $club = $this->sandbox->club();
            $equipment = $scenario['ticket']['equipment'] ? $this->sandbox->equipment($scenario['ticket']['equipment']) : null;
            $ticket = new Ticket([
                'club_id' => $club->id, 'equipment_id' => $equipment?->id, 'category' => $scenario['ticket']['category'],
                'description' => $scenario['ticket']['description'], 'priority' => $scenario['ticket']['priority'],
                'initiator_id' => $this->sandbox->clientId(), 'assignee_id' => $user->id,
            ]);
            $ticket->forceFill(['training_user_id' => $user->id])->save();

            return TrainingRun::create([
                'user_id' => $user->id, 'scenario_id' => $scenario['id'], 'ticket_id' => $ticket->id,
                'status' => 'active', 'checks' => [], 'started_at' => now(),
            ]);
        });
    }

    /** A diagnostic action on the simulated site. Available while the ticket is being worked. */
    public function check(User $user, TrainingRun $run, string $checkId): array
    {
        abort_unless($run->user_id === $user->id, 404);
        $scenario = $this->scenario($run->scenario_id);
        $check = collect($scenario['checks'])->firstWhere('id', $checkId);
        abort_unless($check, 404);
        if ($run->status !== 'active') {
            throw ValidationException::withMessages(['check' => 'Учебная заявка уже завершена.']);
        }
        $ticket = Ticket::findOrFail($run->ticket_id);
        if (!in_array($ticket->status, ['working', 'parts'], true)) {
            throw ValidationException::withMessages(['check' => 'Диагностика доступна, когда заявка в статусе «В работе». Сначала примите заявку и переведите её в работу.']);
        }
        if (!collect($run->checks)->contains('id', $checkId)) {
            $run->checks = [...$run->checks, ['id' => $checkId, 'at' => now()->toIso8601String()]];
            $run->save();
        }

        return $check;
    }

    /** Called after every transition or proposal; the simulated client reacts to practice tickets. */
    public function afterChange(Ticket $ticket): void
    {
        if (!$ticket->isTraining()) {
            return;
        }
        $run = TrainingRun::where('ticket_id', $ticket->id)->where('status', 'active')->first();
        if (!$run) {
            return;
        }
        $scenario = $this->scenario($run->scenario_id);
        match ($ticket->status) {
            'approval' => $this->clientApproves($ticket, $run, $scenario),
            'resolved' => $this->clientReviews($ticket, $run, $scenario),
            'closed' => $this->finish($run),
            default => null,
        };
    }

    private function clientApproves(Ticket $ticket, TrainingRun $run, array $scenario): void
    {
        DB::transaction(function () use ($ticket, $run, $scenario) {
            $proposal = DB::table('ticket_proposals')->where('ticket_id', $ticket->id)->where('status', 'pending')->orderByDesc('id')->first();
            if (!$proposal) {
                return;
            }
            $needed = (bool) $scenario['approval'];
            $comment = $needed ? ($scenario['client']['approve'] ?? 'Согласовано.') : ($scenario['client']['approve_unneeded'] ?? 'Согласовано, хотя платные работы здесь не требовались.');
            DB::table('ticket_proposals')->where('id', $proposal->id)->update([
                'status' => 'approved', 'decided_by' => $this->sandbox->clientId(), 'decided_at' => now(), 'decision_comment' => $comment,
            ]);
            if (!$needed) {
                $run->update(['unneeded_proposal' => true]);
            }
            $this->clientSets($ticket, 'working', 'Согласовано предложение #'.$proposal->id.'. '.$comment);
        });
    }

    private function clientReviews(Ticket $ticket, TrainingRun $run, array $scenario): void
    {
        $verified = $this->matches($ticket->verification_result, $scenario['expect']['verification']) === count($scenario['expect']['verification']);
        if (!$verified && $run->reworks === 0) {
            $run->increment('reworks');
            $this->clientSets($ticket, 'working', 'Возвращено на доработку. '.$scenario['client']['rework']);

            return;
        }
        $this->clientSets($ticket, 'closed', 'Клиент подтвердил результат. '.$scenario['client']['confirm']);
        $this->finish($run);
    }

    private function clientSets(Ticket $ticket, string $status, string $comment): void
    {
        $ticket = Ticket::lockForUpdate()->findOrFail($ticket->id);
        $previous = $ticket->status;
        $ticket->status = $status;
        $ticket->version++;
        if ($status === 'closed') {
            $ticket->closed_at = now();
        }
        if ($status === 'working') {
            $ticket->resolved_at = null;
            $ticket->closed_at = null;
        }
        $ticket->save();
        $ticket->events()->create(['actor_id' => $this->sandbox->clientId(), 'from_status' => $previous, 'to_status' => $status, 'comment' => $comment]);
    }

    // ---- Grading -------------------------------------------------------------------------------------

    public function finish(TrainingRun $run): void
    {
        if ($run->status !== 'active') {
            return;
        }
        $scenario = $this->scenario($run->scenario_id);
        $ticket = Ticket::with('events')->findOrFail($run->ticket_id);
        $items = $this->grade($run, $ticket, $scenario);
        $score = array_sum(array_column($items, 'points'));
        $run->update([
            'status' => 'finished', 'breakdown' => $items, 'score' => $score, 'max_score' => 100,
            'passed' => $score >= self::PASS_SCORE, 'finished_at' => now(),
        ]);
    }

    private function grade(TrainingRun $run, Ticket $ticket, array $scenario): array
    {
        $events = $ticket->events->sortBy('id');
        $mine = $events->where('actor_id', $run->user_id);
        $accepted = $mine->firstWhere('to_status', 'accepted');
        $reaction = $accepted ? (int) floor($run->started_at->diffInMinutes($accepted->created_at)) : null;
        $keys = collect($scenario['checks'])->where('key', true);
        $done = collect($run->checks)->pluck('id');
        $keysDone = $keys->whereIn('id', $done);
        $harmful = collect($scenario['checks'])->where('harmful', true)->whereIn('id', $done);
        $diag = max(0, (int) round($keysDone->count() / max(1, $keys->count()) * 20) - 10 * $harmful->count());
        $orderOk = $mine->contains('to_status', 'accepted') && $mine->contains('to_status', 'working') && $mine->contains('to_status', 'resolved');
        $logged = DB::table('work_logs')->where('ticket_id', $ticket->id)->exists();
        $proposed = DB::table('ticket_proposals')->where('ticket_id', $ticket->id)->exists();
        $expect = $scenario['expect'];
        $part = fn (?string $text, array $groups, int $max) => (int) round($this->matches($text, $groups) / count($groups) * $max);

        $items = [
            ['key' => 'reaction', 'label' => 'Быстро принял заявку', 'max' => 10,
                'points' => $reaction === null ? 0 : ($reaction <= 5 ? 10 : ($reaction <= 15 ? 5 : 0)),
                'note' => $reaction === null ? 'Заявка не была принята.' : 'Принята через '.$reaction.' мин после поступления (10 баллов — до 5 мин, 5 — до 15 мин).'],
            ['key' => 'order', 'label' => 'Статусы по порядку: Принята → В работе → Решена', 'max' => 5, 'points' => $orderOk ? 5 : 0,
                'note' => $orderOk ? 'Заявка прошла все рабочие статусы.' : 'Пропущен один из статусов.'],
            ['key' => 'diagnostics', 'label' => 'Диагностика: ключевые проверки', 'max' => 20, 'points' => $diag,
                'note' => 'Выполнено ключевых проверок: '.$keysDone->count().' из '.$keys->count().'.'
                    .($keys->whereNotIn('id', $done)->isNotEmpty() ? ' Пропущено: '.$keys->whereNotIn('id', $done)->pluck('label')->map(fn ($l) => '«'.$l.'»')->implode(', ').'.' : '')
                    .($harmful->isNotEmpty() ? ' Лишние или вредные действия (−10 за каждое): '.$harmful->pluck('label')->map(fn ($l) => '«'.$l.'»')->implode(', ').'.' : '')],
            ['key' => 'worklog', 'label' => 'Записаны трудозатраты и действия', 'max' => 5, 'points' => $logged ? 5 : 0,
                'note' => $logged ? 'Запись в «Трудозатраты и расходы» есть.' : 'Нет записи в блоке «Трудозатраты и расходы».'],
            ['key' => 'cause', 'label' => 'Причина указана верно', 'max' => 20, 'points' => $part($ticket->cause, $expect['cause'], 20),
                'note' => 'Сравнивается с ключевыми признаками причины этого инцидента.'],
            ['key' => 'solution', 'label' => 'Решение устраняет причину', 'max' => 15, 'points' => $part(trim($ticket->solution.' '.$ticket->work_result), $expect['solution'], 15),
                'note' => 'Решение должно описывать, что именно исправлено.'],
            ['key' => 'verification', 'label' => 'Описана проверка результата', 'max' => 10, 'points' => $part($ticket->verification_result, $expect['verification'], 10),
                'note' => 'Проверка — как вы убедились, что всё работает: старт, запуск, нагрузка.'],
            ['key' => 'approval', 'label' => $scenario['approval'] ? 'Платные работы согласованы с клубом' : 'Без лишних платных работ', 'max' => 10,
                'points' => $scenario['approval'] ? ($proposed ? 10 : 0) : ($proposed ? 0 : 10),
                'note' => $scenario['approval']
                    ? ($proposed ? 'Предложение работ отправлено и согласовано.' : 'Ремонт за счёт клуба выполнен без предложения работ и согласования.')
                    : ($proposed ? 'Отправлено предложение платных работ, которые не требовались.' : 'Платные работы не требовались и не предлагались.')],
            ['key' => 'first_time', 'label' => 'Принято клиентом с первого раза', 'max' => 5, 'points' => $run->reworks ? 0 : 5,
                'note' => $run->reworks ? 'Клиент возвращал заявку на доработку.' : 'Без возврата на доработку.'],
        ];

        return $items;
    }

    /** Number of keyword groups found in the text; a group matches when any of its stems occurs. */
    private function matches(?string $text, array $groups): int
    {
        $text = str_replace('ё', 'е', mb_strtolower((string) $text));

        return collect($groups)->filter(fn ($alts) => collect($alts)->contains(fn ($stem) => str_contains($text, str_replace('ё', 'е', mb_strtolower($stem)))))->count();
    }

    // ---- Views -----------------------------------------------------------------------------------------

    /** Training panel on the ticket page. */
    public function forTicket(Ticket $ticket): ?array
    {
        $run = TrainingRun::where('ticket_id', $ticket->id)->latest('id')->first();
        if (!$run) {
            return null;
        }
        $scenario = $this->scenario($run->scenario_id);
        $done = collect($run->checks)->keyBy('id');

        return [
            'run_id' => $run->id, 'status' => $run->status, 'started_at' => $run->started_at->toIso8601String(),
            'scenario' => $this->card($scenario),
            'can_check' => $run->status === 'active' && in_array($ticket->status, ['working', 'parts'], true),
            'checks' => collect($scenario['checks'])->map(fn ($c) => [
                'id' => $c['id'], 'label' => $c['label'], 'done' => $done->has($c['id']),
                'finding' => $done->has($c['id']) || $run->status === 'finished' ? $c['finding'] : null,
                'harmful' => ($done->has($c['id']) || $run->status === 'finished') ? (bool) ($c['harmful'] ?? false) : null,
                'key' => $run->status === 'finished' ? (bool) ($c['key'] ?? false) : null,
            ])->values()->all(),
            'result' => $run->status === 'finished' ? $this->result($run, $scenario) : null,
        ];
    }

    /** Practice block of a program for the training page. */
    public function programState(array $track, Collection $runs): ?array
    {
        if (empty($track['practice'])) {
            return null;
        }
        $items = collect($track['practice']['scenarios'])->map(function ($id) use ($runs) {
            $scenario = $this->scenario($id);
            $mine = $runs->where('scenario_id', $id);
            $best = $mine->where('status', 'finished')->sortByDesc('score')->first();
            $active = $mine->firstWhere('status', 'active');

            return $this->card($scenario) + [
                'attempts' => $mine->where('status', 'finished')->count(),
                'best' => $best ? ['score' => $best->score, 'passed' => $best->passed, 'run_id' => $best->id, 'ticket_id' => $best->ticket_id,
                    'duration_seconds' => (int) $best->started_at->diffInSeconds($best->finished_at)] : null,
                'active_ticket_id' => $active?->ticket_id,
            ];
        })->values();

        return [
            'pass_score' => $track['practice']['pass_score'] ?? self::PASS_SCORE,
            'scenarios' => $items->all(),
            'passed' => $items->filter(fn ($s) => $s['best']['passed'] ?? false)->count(),
            'total' => $items->count(),
            'average' => ($scores = $items->pluck('best.score')->filter(fn ($v) => $v !== null))->isNotEmpty() ? (int) round($scores->avg()) : null,
        ];
    }

    private function card(array $scenario): array
    {
        return [
            'id' => $scenario['id'], 'title' => $scenario['title'], 'summary' => $scenario['summary'], 'level' => $scenario['level'],
            'target_minutes' => $scenario['target_minutes'],
            'chapters' => collect($scenario['chapters'])->map(fn ($id) => ['id' => $id, 'title' => $this->library->find($id)?->data['title'] ?? $id])->all(),
        ];
    }

    private function result(TrainingRun $run, array $scenario): array
    {
        return [
            'score' => $run->score, 'max_score' => $run->max_score, 'passed' => $run->passed, 'pass_score' => self::PASS_SCORE,
            'duration_seconds' => (int) $run->started_at->diffInSeconds($run->finished_at), 'target_minutes' => $scenario['target_minutes'],
            'breakdown' => $run->breakdown, 'model' => $scenario['model'],
        ];
    }
}
