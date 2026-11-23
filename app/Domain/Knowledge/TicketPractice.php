<?php

namespace App\Domain\Knowledge;

use App\Domain\Tickets\TicketStatus;
use App\Models\{TrainingTicketRun, User};
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Ticket practice: a sandbox incident handled with the same statuses, transitions and closing rules
 * as a real C+CLub ticket. Runs live only in training_ticket_runs — never in tickets, reports,
 * statistics, Telegram or the external API. The "club" (approval, rework, confirmation) is simulated.
 *
 * Score out of 100: ticket intake 20, diagnostics 25, fix 10, approval 5, verification 10,
 * root cause 10, closing fields and work log 10, no rework 5, time 5; −10 for every harmful action.
 * Passed: at least 80 points and no harmful actions. Demo runs show hints and do not count.
 */
final class TicketPractice
{
    public const PASS_POINTS = 80;

    public const HARMFUL_PENALTY = 10;

    public const ROLES = ['owner', 'lead', 'specialist'];

    public const CATEGORIES = ['Диагностика', 'Ремонт', 'Чистка ПК', 'Обслуживание'];

    private ?array $content = null;

    public function content(): array
    {
        return $this->content ??= json_decode(file_get_contents(resource_path('knowledge/ticket-scenarios.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function scenarios(): array
    {
        return $this->content()['scenarios'];
    }

    public function scenario(string $id): ?array
    {
        return collect($this->scenarios())->firstWhere('id', $id);
    }

    public function allows(User $user): bool
    {
        return $user->active && in_array($user->role, self::ROLES, true);
    }

    // ---- Runs ---------------------------------------------------------------------------------------

    /** Resumes an unfinished run of this scenario and mode, or starts a new one. */
    public function start(User $user, string $scenarioId, string $mode): TrainingTicketRun
    {
        abort_unless($this->scenario($scenarioId) && in_array($mode, ['demo', 'exam'], true), 404);
        $open = TrainingTicketRun::where('user_id', $user->id)->where('scenario_id', $scenarioId)->where('mode', $mode)->whereNull('finished_at')->first();
        if ($open) {
            return $open;
        }

        return TrainingTicketRun::create([
            'user_id' => $user->id, 'scenario_id' => $scenarioId, 'mode' => $mode, 'status' => 'message', 'started_at' => now(),
            'state' => ['ticket' => null, 'fields' => [], 'done' => [], 'fix' => null, 'proposal' => null, 'worklogs' => [], 'reworks' => 0,
                'timeline' => [['at' => now()->toIso8601String(), 'kind' => 'club', 'text' => 'Входящее сообщение от клуба. Оформите заявку.']]],
        ]);
    }

    /** Applies one engineer step. Throws validation errors with the same wording as real tickets. */
    public function act(TrainingTicketRun $run, string $type, array $input): void
    {
        abort_if((bool) $run->finished_at, 409, 'Учебная заявка уже закрыта.');
        $scenario = $this->scenario($run->scenario_id);
        $state = $run->state;
        match ($type) {
            'create' => $this->create($run, $scenario, $state, $input),
            'transition' => $this->transition($run, $scenario, $state, $input),
            'action' => $this->action($run, $scenario, $state, $input),
            'proposal' => $this->proposal($run, $scenario, $state, $input),
            'worklog' => $this->worklog($run, $state, $input),
            default => abort(422),
        };
        $run->state = $state;
        if ($run->status === 'closed') {
            $run->finished_at = now();
            $result = $this->score($run);
            $run->score = $result['score'];
            $run->passed = $result['passed'];
        }
        $run->save();
    }

    private function create(TrainingTicketRun $run, array $scenario, array &$state, array $input): void
    {
        $this->expect($run->status === 'message', 'Заявка уже создана.');
        $ids = array_column($scenario['equipment'], 'id');
        $data = validator($input, [
            'equipment_id' => ['nullable', 'string', 'in:'.implode(',', $ids)],
            'category' => 'required|string|max:80',
            'description' => 'required|string|min:10|max:2000',
            'priority' => 'required|in:low,normal,high,critical',
        ], [], ['equipment_id' => 'оборудование', 'category' => 'категория', 'description' => 'описание', 'priority' => 'приоритет'])->validate();
        $state['ticket'] = [
            'equipment_id' => $data['equipment_id'] ?? null, 'category' => trim($data['category']),
            'description' => trim($data['description']), 'priority' => $data['priority'], 'created_at' => now()->toIso8601String(),
        ];
        $run->status = TicketStatus::New->value;
        $this->log($state, 'status', 'Заявка создана · Новая');
    }

    private function transition(TrainingTicketRun $run, array $scenario, array &$state, array $input): void
    {
        $this->expect($run->status !== 'message', 'Сначала создайте заявку.');
        $to = TicketStatus::tryFrom((string) ($input['to'] ?? ''));
        $from = TicketStatus::from($run->status);
        $this->expect($to !== null && $to !== TicketStatus::Approval && $from !== TicketStatus::Approval,
            'Используйте предложение работ и решение владельца клуба.');
        $this->expect($to !== TicketStatus::Parts, 'В практикуме запчасти не ожидаются — оформите предложение работ, если нужна покупка.');
        $this->expect($from->canMoveTo($to), 'Этот переход статуса не разрешён.');
        if ($to === TicketStatus::Closed && $state['proposal']) {
            throw ValidationException::withMessages(['status' => 'Результат согласованных работ должен подтвердить владелец клуба.']);
        }
        if ($to === TicketStatus::Resolved) {
            $fields = validator($input, [
                'work_result' => 'required|string|max:5000', 'verification_result' => 'required|string|max:5000',
                'cause' => 'required|string|max:5000', 'solution' => 'required|string|max:5000',
                'cause_option' => ['required', 'string', 'in:'.implode(',', array_column($scenario['causes'], 'id'))],
            ], ['required' => 'Укажите результат, проверку, причину и решение.', 'cause_option.required' => 'Выберите основную причину из списка.'])->validate();
            $state['fields'] = array_map('trim', $fields);
        }
        $run->status = $to->value;
        $labels = ['accepted' => 'Принята', 'working' => 'В работе', 'resolved' => 'Решена', 'closed' => 'Закрыта'];
        $this->log($state, 'status', 'Статус: '.$labels[$to->value], $input['comment'] ?? null);
        if ($to === TicketStatus::Accepted && !isset($state['accepted_at'])) {
            $state['accepted_at'] = now()->toIso8601String();
        }
        if ($to === TicketStatus::Resolved) {
            $this->clubReviews($run, $scenario, $state);
        }
    }

    /** The simulated club checks the result: rework when the cause was not removed, confirmation for agreed works. */
    private function clubReviews(TrainingTicketRun $run, array $scenario, array &$state): void
    {
        if (!$state['fix']) {
            $state['reworks']++;
            $run->status = TicketStatus::Working->value;
            $this->log($state, 'club', 'Клуб вернул заявку на доработку: проблема не устранена, симптом сохраняется.');
            $this->log($state, 'status', 'Статус: В работе');

            return;
        }
        if ($state['proposal']) {
            $run->status = TicketStatus::Closed->value;
            $this->log($state, 'club', 'Владелец клуба подтвердил результат согласованных работ.');
            $this->log($state, 'status', 'Статус: Закрыта');
        }
    }

    private function action(TrainingTicketRun $run, array $scenario, array &$state, array $input): void
    {
        $this->expect($run->status === TicketStatus::Working->value, 'Действия выполняются, когда заявка «В работе».');
        $action = collect($scenario['actions'])->firstWhere('id', $input['action'] ?? null);
        abort_unless($action, 422);
        $afterFix = (bool) $state['fix'];
        $repeatable = $action['group'] === 'verify';
        $this->expect($repeatable || !collect($state['done'])->contains('id', $action['id']), 'Это действие уже выполнено — результат есть в истории заявки.');
        $result = $action['result'];
        if ($action['group'] === 'verify' && !$afterFix) {
            $result = $action['result_before_fix'] ?? $result;
        }
        if ($action['role'] === 'fix') {
            $approved = ($state['proposal']['status'] ?? null) === 'approved';
            $unapproved = !empty($action['needs_approval']) && !$approved;
            if ($unapproved) {
                $result = $action['result_without_approval'] ?? $result;
            }
            $state['fix'] = ['id' => $action['id'], 'at' => now()->toIso8601String(), 'unapproved' => $unapproved];
        }
        $state['done'][] = ['id' => $action['id'], 'at' => now()->toIso8601String(), 'after_fix' => $afterFix];
        $this->log($state, 'action', $action['label'], $result, $action['role'] === 'harmful');
    }

    private function proposal(TrainingTicketRun $run, array $scenario, array &$state, array $input): void
    {
        $this->expect($run->status === TicketStatus::Working->value, 'Предложение доступно в работе или на согласовании.');
        $data = validator($input, [
            'description' => 'required|string|min:10|max:2000', 'amount' => 'required|numeric|min:0|max:100000',
            'expected_duration' => 'required|string|max:120',
        ], [], ['description' => 'описание работ', 'amount' => 'сумма', 'expected_duration' => 'срок'])->validate();
        $this->log($state, 'proposal', 'Отправлено предложение на согласование · '.$data['amount'].' USD · '.$data['expected_duration'], $data['description']);
        $this->log($state, 'status', 'Статус: Ожидает согласования');
        $approval = $scenario['approval'];
        if (!$approval) {
            $this->log($state, 'club', 'Клуб: эти работы входят в обслуживание и не требуют согласования. Выполняйте.');
        } elseif ($data['amount'] < $approval['amount_min'] || $data['amount'] > $approval['amount_max']) {
            $this->log($state, 'club', 'Клуб отклонил предложение: сумма не соответствует работам. Уточните стоимость и отправьте снова.');
        } else {
            $state['proposal'] = ['description' => $data['description'], 'amount' => (float) $data['amount'], 'expected_duration' => $data['expected_duration'],
                'status' => 'approved', 'approved_at' => now()->toIso8601String()];
            $this->log($state, 'club', 'Согласовано. '.$approval['club_reply']);
        }
        $this->log($state, 'status', 'Статус: В работе');
    }

    private function worklog(TrainingTicketRun $run, array &$state, array $input): void
    {
        $this->expect(in_array($run->status, ['working', 'resolved'], true), 'Трудозатраты записываются в работе или после решения.');
        $data = validator($input, ['minutes' => 'required|integer|min:1|max:1440', 'actions' => 'required|string|min:5|max:2000', 'consumables' => 'nullable|string|max:1000'],
            [], ['minutes' => 'минуты', 'actions' => 'выполненные действия', 'consumables' => 'расходники'])->validate();
        $state['worklogs'][] = $data + ['at' => now()->toIso8601String()];
        $this->log($state, 'worklog', 'Трудозатраты: '.$data['minutes'].' мин', $data['actions'].(filled($data['consumables'] ?? null) ? ' · Расходники: '.$data['consumables'] : ''));
    }

    // ---- Scoring ------------------------------------------------------------------------------------

    /** Breakdown of a run (finished or not, for demo feedback). */
    public function score(TrainingTicketRun $run): array
    {
        $scenario = $this->scenario($run->scenario_id);
        $s = $run->state;
        $t = $s['ticket'] ?? [];
        $expected = $scenario['expected'];
        $done = collect($s['done']);
        $fixAt = $s['fix']['at'] ?? null;
        $actions = collect($scenario['actions'])->keyBy('id');
        $rows = [];
        $add = function (string $label, float $points, int $max, string $note) use (&$rows) {
            $rows[] = ['label' => $label, 'points' => (int) round($points), 'max' => $max, 'note' => $note];
        };

        // 1. Intake (20)
        $equipmentOk = in_array($t['equipment_id'] ?? null, $expected['equipment_ok'] ?? [$expected['equipment']], true);
        $categories = (array) $expected['category'];
        $categoryOk = in_array(mb_strtolower(trim($t['category'] ?? '')), array_map('mb_strtolower', $categories), true);
        $priorityOk = in_array($t['priority'] ?? null, $expected['priority'], true);
        $desc = mb_strtolower($t['description'] ?? '');
        $keywords = collect($expected['keywords'])->filter(fn ($k) => str_contains($desc, mb_strtolower($k)))->count();
        $descPoints = mb_strlen($desc) >= 40 && $keywords > 0 ? 5 : (mb_strlen($desc) >= 20 ? 2 : 0);
        $add('Оформление заявки', ($equipmentOk ? 5 : 0) + ($categoryOk ? 5 : 0) + ($priorityOk ? 5 : 0) + $descPoints, 20, implode(' · ', [
            $equipmentOk ? 'оборудование верно' : 'неверная привязка к оборудованию',
            $categoryOk ? 'категория верна' : 'категория: ожидалась «'.$categories[0].'»',
            $priorityOk ? 'приоритет верен' : 'приоритет не соответствует масштабу',
            $descPoints === 5 ? 'описание содержательное' : 'описание без деталей (текст ошибки, масштаб, что делали)',
        ]));

        // 2. Diagnostics (25): key checks done before the fix.
        $keys = $actions->where('role', 'key')->keys();
        $keysDone = $keys->filter(fn ($id) => $done->contains(fn ($d) => $d['id'] === $id && !$d['after_fix']));
        $missed = $keys->diff($keysDone)->map(fn ($id) => '«'.$actions[$id]['label'].'»');
        $add('Диагностика', $keys->count() ? 25 * $keysDone->count() / $keys->count() : 25, 25,
            $missed->isEmpty() ? 'все ключевые проверки сделаны до исправления' : 'пропущено: '.$missed->implode(', '));

        // 3. Fix (10)
        $add('Устранение причины', $fixAt ? 10 : 0, 10, $fixAt ? 'причина устранена' : 'причина не устранена');

        // 4. Approval (5)
        if ($scenario['approval']) {
            $approved = $fixAt && !($s['fix']['unapproved'] ?? true);
            $add('Согласование с клубом', $approved ? 5 : 0, 5, $approved ? 'работы согласованы до выполнения' : 'платные работы выполнены без согласования клуба');
        } else {
            $add('Согласование с клубом', 5, 5, 'не требовалось');
        }

        // 5. Verification (10): required checks after the fix.
        $verify = $actions->filter(fn ($a) => $a['group'] === 'verify' && !empty($a['required']))->keys();
        $verified = $verify->filter(fn ($id) => $done->contains(fn ($d) => $d['id'] === $id && $d['after_fix']));
        $add('Проверка результата', $verify->count() ? 10 * $verified->count() / $verify->count() : 10, 10,
            $verified->count() === $verify->count() ? 'результат проверен после исправления' : 'не все обязательные проверки после исправления');

        // 6. Root cause (10)
        $cause = collect($scenario['causes'])->firstWhere('id', $s['fields']['cause_option'] ?? null);
        $add('Причина', !empty($cause['correct']) ? 10 : 0, 10, $cause ? (!empty($cause['correct']) ? 'причина определена верно' : 'выбрана неверная причина: «'.$cause['label'].'»') : 'причина не указана');

        // 7. Closing fields and work log (10)
        $filled = collect(['work_result', 'verification_result', 'cause', 'solution'])->filter(fn ($f) => mb_strlen(trim($s['fields'][$f] ?? '')) >= 25)->count();
        $worklog = collect($s['worklogs'])->isNotEmpty();
        $add('Закрытие и трудозатраты', $filled * 2 + ($worklog ? 2 : 0), 10,
            ($filled === 4 ? 'все поля закрытия заполнены подробно' : 'подробно заполнено полей: '.$filled.' из 4 (нужно от 25 символов)').' · '.($worklog ? 'трудозатраты записаны' : 'нет записи трудозатрат'));

        // 8. No rework (5)
        $add('Без возврата на доработку', $s['reworks'] ? 0 : 5, 5, $s['reworks'] ? 'клуб возвращал заявку: '.$s['reworks'] : 'с первого раза');

        // 9. Time (5)
        $minutes = $run->started_at->diffInMinutes($run->finished_at ?? now());
        $target = $scenario['minutes_target'];
        $add('Сроки', $minutes <= $target ? 5 : ($minutes <= $target * 2 ? 2 : 0), 5, 'выполнено за '.max(1, (int) round($minutes)).' мин при норме '.$target);

        $harmful = $done->filter(fn ($d) => ($actions[$d['id']]['role'] ?? null) === 'harmful')
            ->map(fn ($d) => ['label' => $actions[$d['id']]['label'], 'reason' => $actions[$d['id']]['penalty'] ?? ''])->values();
        $total = max(0, collect($rows)->sum('points') - $harmful->count() * self::HARMFUL_PENALTY);

        return [
            'score' => $total, 'max' => 100, 'pass_points' => self::PASS_POINTS,
            'passed' => $total >= self::PASS_POINTS && $harmful->isEmpty(),
            'rows' => $rows, 'harmful' => $harmful->all(), 'penalty_per_harmful' => self::HARMFUL_PENALTY,
            'minutes' => (int) round($minutes),
        ];
    }

    // ---- Views --------------------------------------------------------------------------------------

    /** What the run page may show: no roles, results of unperformed actions, correct causes or expectations. */
    public function present(TrainingTicketRun $run): array
    {
        $scenario = $this->scenario($run->scenario_id);
        $s = $run->state;

        return [
            'run' => [
                'id' => $run->id, 'mode' => $run->mode, 'status' => $run->status, 'started_at' => $run->started_at->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(), 'ticket' => $s['ticket'], 'fields' => $s['fields'],
                'done' => collect($s['done'])->pluck('id')->unique()->values(), 'fix_done' => (bool) $s['fix'],
                'proposal' => $s['proposal'], 'worklogs' => $s['worklogs'], 'timeline' => $s['timeline'],
            ],
            'scenario' => [
                'id' => $scenario['id'], 'title' => $scenario['title'], 'difficulty' => $scenario['difficulty'], 'minutes_target' => $scenario['minutes_target'],
                'club' => $this->content()['club'], 'message' => $scenario['message'], 'equipment' => $scenario['equipment'],
                'actions' => array_map(fn ($a) => ['id' => $a['id'], 'group' => $a['group'], 'label' => $a['label']], $scenario['actions']),
                'causes' => array_map(fn ($c) => ['id' => $c['id'], 'label' => $c['label']], $scenario['causes']),
                'chapters' => $scenario['chapters'], 'categories' => self::CATEGORIES,
            ],
            'hint' => $run->mode === 'demo' && !$run->finished_at ? $this->hint($run, $scenario) : null,
            'result' => $run->finished_at ? $this->score($run) : null,
        ];
    }

    private function hint(TrainingTicketRun $run, array $scenario): array
    {
        $s = $run->state;
        $actions = collect($scenario['actions']);
        $done = collect($s['done']);
        $stage = match ($run->status) {
            'message' => 'create', 'new' => 'accept', 'accepted' => 'work', 'resolved' => 'close',
            default => match (true) {
                $actions->where('role', 'key')->contains(fn ($a) => !$done->contains('id', $a['id'])) && !$s['fix'] => 'diagnose',
                !$s['fix'] && $scenario['approval'] && !$s['proposal'] => 'approval',
                !$s['fix'] => 'fix',
                $actions->filter(fn ($a) => !empty($a['required']))->contains(fn ($a) => !$done->contains(fn ($d) => $d['id'] === $a['id'] && $d['after_fix'])) => 'verify',
                empty($s['worklogs']) => 'worklog',
                default => 'resolve',
            },
        };

        return ['stage' => $stage, 'text' => $scenario['hints'][$stage] ?? ''];
    }

    /** Practice state for the training page. */
    public function summary(User $user): ?array
    {
        if (!$this->allows($user)) {
            return null;
        }

        return $this->summarize(TrainingTicketRun::where('user_id', $user->id)->get());
    }

    /** Practice results of team members (owner/lead view). */
    public function teamRows(Collection $userIds): Collection
    {
        return TrainingTicketRun::whereIn('user_id', $userIds)->get()->groupBy('user_id')->map(fn ($runs) => $this->summarize($runs));
    }

    private function summarize(Collection $runs): array
    {
        $scenarios = array_map(function ($sc) use ($runs) {
            $mine = $runs->where('scenario_id', $sc['id']);
            $exams = $mine->where('mode', 'exam')->whereNotNull('finished_at');
            $best = $exams->sortByDesc('score')->first();
            $passed = $exams->where('passed', true)->sortBy('finished_at')->first();

            return [
                'id' => $sc['id'], 'title' => $sc['title'], 'difficulty' => $sc['difficulty'], 'minutes_target' => $sc['minutes_target'],
                'demo_done' => $mine->where('mode', 'demo')->whereNotNull('finished_at')->isNotEmpty(),
                'open_demo' => $mine->where('mode', 'demo')->whereNull('finished_at')->first()?->id,
                'open_exam' => $mine->where('mode', 'exam')->whereNull('finished_at')->first()?->id,
                'attempts' => $exams->count(), 'best' => $best?->score, 'best_run' => $best?->id,
                'passed_at' => $passed?->finished_at->toIso8601String(),
                'last_run' => $exams->sortByDesc('finished_at')->first()?->id,
            ];
        }, $this->scenarios());
        $passed = collect($scenarios)->whereNotNull('passed_at');
        $first = $runs->min('started_at');
        $completed = $passed->count() === count($scenarios) ? $passed->max('passed_at') : null;

        return [
            'scenarios' => $scenarios, 'passed' => $passed->count(), 'total' => count($scenarios), 'pass_points' => self::PASS_POINTS,
            'started_at' => $first?->toIso8601String(), 'completed_at' => $completed,
            'duration_seconds' => $first ? (int) $first->diffInSeconds($completed ? \Illuminate\Support\Carbon::parse($completed) : now()) : null,
        ];
    }

    private function log(array &$state, string $kind, string $text, ?string $detail = null, bool $harmful = false): void
    {
        $state['timeline'][] = array_filter(['at' => now()->toIso8601String(), 'kind' => $kind, 'text' => $text, 'detail' => $detail, 'harmful' => $harmful ?: null], fn ($v) => $v !== null);
    }

    private function expect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }
}
