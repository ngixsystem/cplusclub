<?php

namespace App\Domain\Knowledge;

use App\Models\{TrainingLabAttempt, TrainingLabSubmission, User};
use Illuminate\Support\Collection;

/**
 * Cisco practice for engineers: subnet trainer, IOS command trainer and Packet Tracer labs reviewed by a lead.
 * Content: resources/knowledge/cisco-labs.json. Subnet tasks are generated and graded on the server.
 */
final class Labs
{
    public const ROLES = ['owner', 'lead', 'specialist'];

    public const SUBNET_TASKS = 10;

    public const PASS = 80;

    public const SOLUTION_AFTER_FAILS = 3;

    /** Who reviews whose Packet Tracer work: the owner — leads and engineers, a lead — engineers and other leads. */
    private const REVIEW_SCOPE = ['owner' => ['lead', 'specialist'], 'lead' => ['lead', 'specialist']];

    private ?array $content = null;

    public function content(): array
    {
        return $this->content ??= json_decode(file_get_contents(resource_path('knowledge/cisco-labs.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function allows(User $user): bool
    {
        return $user->active && in_array($user->role, self::ROLES, true);
    }

    public function canReview(User $reviewer, ?User $author = null): bool
    {
        $roles = self::REVIEW_SCOPE[$reviewer->role] ?? [];

        return $reviewer->active && $roles && (!$author || ($author->id !== $reviewer->id && in_array($author->role, $roles, true)));
    }

    public function iosTask(string $id): ?array
    {
        return collect($this->content()['ios'])->firstWhere('id', $id);
    }

    public function lab(string $id): ?array
    {
        return collect($this->content()['packet_tracer'])->firstWhere('id', $id);
    }

    // ---- IOS command trainer ---------------------------------------------------------------------------

    /**
     * Parses pasted IOS commands into (context, command) pairs. Prompts like "SW1(config-if)#" are stripped,
     * commands are lowercased, interface names are written without spaces ("fa 0/1" → "fa0/1").
     *
     * @return list<array{0: ?string, 1: string}>
     */
    public function parseIos(string $input): array
    {
        $lines = [];
        $context = null;
        foreach (preg_split('/\R/u', $input) as $raw) {
            $line = preg_replace('/^[\w.-]+(\([\w-]+\))?[#>]\s*/u', '', trim($raw));
            $line = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $line)));
            if ($line === '' || $line === '!' || str_starts_with($line, '!')) {
                continue;
            }
            if (preg_match('/^(end|exit)$/', $line)) {
                $context = null;
                continue;
            }
            if (preg_match('/^(en|ena|enab|enabl|enable|disable|conf|conf t|config t|configure|configure terminal)$/', $line)) {
                continue;
            }
            $enters = null;
            if (preg_match('/^int(e(r(f(a(c(e)?)?)?)?)?)? (.+)$/', $line, $m)) {
                $enters = preg_replace('/^ra(n(ge?)?)? /', 'range ', $m[7]);
                $enters = preg_replace('/^(range )?([a-z-]+) ?(\d)/', '$1$2$3', $enters);
                if (str_starts_with($enters, 'range ')) {
                    $enters = preg_replace('/ ?- ?(?=\S*$)/', ' - ', $enters);
                }
            } elseif (preg_match('/^line (con(sole)?|vty|aux) (.+)$/', $line, $m)) {
                $enters = 'line '.($m[1] === 'console' ? 'con' : $m[1]).' '.$m[3];
            } elseif (preg_match('/^vlan (\d+)$/', $line, $m)) {
                $enters = 'vlan '.$m[1];
            } elseif (preg_match('/^ip dhcp pool (\S+)$/', $line, $m)) {
                $enters = 'pool '.$m[1];
            } elseif (preg_match('/^ip access-list (standard|extended|ext|std) (\S+)$/', $line, $m)) {
                $enters = 'acl '.$m[2];
            } elseif (preg_match('/^router (.+)$/', $line, $m)) {
                $enters = 'router '.$m[1];
            }
            $lines[] = [$enters !== null ? null : $context, $line];
            if ($enters !== null) {
                $context = $enters;
            }
        }

        return $lines;
    }

    /** Checks commands against a task: each requirement and forbidden pattern, optionally inside a context. */
    public function checkIos(array $task, string $input): array
    {
        $lines = $this->parseIos($input);
        $found = function (array $rule) use ($lines): bool {
            foreach ($lines as [$context, $command]) {
                if (isset($rule['context']) && ($context === null || !preg_match('~'.$rule['context'].'~u', $context))) {
                    continue;
                }
                if (preg_match('~'.$rule['pattern'].'~u', $command)) {
                    return true;
                }
            }

            return false;
        };
        $results = array_map(fn ($r) => ['label' => $r['label'], 'ok' => $found($r)], $task['requirements']);
        $violations = array_values(array_map(fn ($r) => $r['label'], array_filter($task['forbidden'] ?? [], $found)));
        $ok = count(array_filter($results, fn ($r) => $r['ok']));
        $score = (int) floor($ok * 100 / max(1, count($results)));

        return ['results' => $results, 'violations' => $violations, 'score' => $score, 'passed' => $ok === count($results) && !$violations, 'commands' => count($lines)];
    }

    public function submitIos(User $user, array $task, string $input): TrainingLabAttempt
    {
        $check = $this->checkIos($task, $input);

        return TrainingLabAttempt::create([
            'user_id' => $user->id, 'kind' => 'ios', 'subject' => $task['id'], 'payload' => ['input' => $input],
            'result' => $check, 'score' => $check['score'], 'passed' => $check['passed'], 'started_at' => now(), 'finished_at' => now(),
        ]);
    }

    public function iosState(User $user): array
    {
        $attempts = TrainingLabAttempt::where('user_id', $user->id)->where('kind', 'ios')->orderBy('id')->get()->groupBy('subject');

        return array_map(function ($task) use ($attempts) {
            $mine = $attempts[$task['id']] ?? collect();
            $passed = $mine->firstWhere('passed', true);
            $fails = $mine->where('passed', false)->count();
            $last = $mine->last();

            return [
                'id' => $task['id'], 'title' => $task['title'], 'level' => $task['level'], 'task' => $task['task'], 'hint' => $task['hint'],
                'requirements' => count($task['requirements']), 'attempts' => $mine->count(), 'passed' => (bool) $passed,
                'best' => $mine->max('score'), 'last' => $last ? ['input' => $last->payload['input'], 'result' => $last->result] : null,
                'solution' => $passed || $fails >= self::SOLUTION_AFTER_FAILS ? $task['solution'] : null,
            ];
        }, $this->content()['ios']);
    }

    // ---- Subnet trainer --------------------------------------------------------------------------------

    public function startSubnets(User $user): TrainingLabAttempt
    {
        $open = TrainingLabAttempt::where('user_id', $user->id)->where('kind', 'subnet')->whereNull('finished_at')->latest('id')->first();
        if ($open && $open->started_at->gt(now()->subHour())) {
            return $open;
        }
        $types = ['network', 'broadcast', 'hosts', 'mask', 'prefix', 'first', 'last', 'same', 'network', 'hosts'];
        shuffle($types);
        $tasks = array_map(fn ($type) => $this->subnetTask($type), array_slice($types, 0, self::SUBNET_TASKS));

        return TrainingLabAttempt::create(['user_id' => $user->id, 'kind' => 'subnet', 'subject' => 'session', 'payload' => ['tasks' => $tasks], 'started_at' => now()]);
    }

    /** Prompts only — the answers stay on the server until the session is submitted. */
    public function presentSubnets(TrainingLabAttempt $attempt): array
    {
        return array_map(fn ($t, $i) => ['n' => $i, 'prompt' => $t['prompt'], 'hint' => $t['format']], $attempt->payload['tasks'], array_keys($attempt->payload['tasks']));
    }

    public function submitSubnets(TrainingLabAttempt $attempt, array $answers): array
    {
        $review = [];
        foreach ($attempt->payload['tasks'] as $i => $task) {
            $given = trim((string) ($answers[$i] ?? ''));
            $ok = $given !== '' && in_array($this->normalizeAnswer($given), array_map(fn ($a) => $this->normalizeAnswer($a), $task['accept']), true);
            $review[] = ['prompt' => $task['prompt'], 'given' => $given, 'answer' => $task['answer'], 'ok' => $ok, 'explain' => $task['explain']];
        }
        $correct = count(array_filter($review, fn ($r) => $r['ok']));
        $score = (int) round($correct * 100 / max(1, count($review)));
        $attempt->forceFill(['answers' => array_column($review, 'given'), 'result' => ['review' => $review, 'correct' => $correct],
            'score' => $score, 'passed' => $score >= self::PASS, 'finished_at' => now()])->save();

        return $this->subnetResult($attempt);
    }

    public function subnetResult(TrainingLabAttempt $attempt): array
    {
        return ['score' => $attempt->score, 'passed' => $attempt->passed, 'correct' => $attempt->result['correct'], 'total' => count($attempt->payload['tasks']),
            'seconds' => (int) $attempt->started_at->diffInSeconds($attempt->finished_at), 'review' => $attempt->result['review']];
    }

    public function subnetState(User $user): array
    {
        $done = TrainingLabAttempt::where('user_id', $user->id)->where('kind', 'subnet')->whereNotNull('finished_at')->get();
        $best = $done->sortBy([['score', 'desc'], [fn ($a, $b) => $a->started_at->diffInSeconds($a->finished_at) <=> $b->started_at->diffInSeconds($b->finished_at)]])->first();

        return [
            'sessions' => $done->count(), 'passed' => $done->where('passed', true)->count(),
            'best' => $best ? ['score' => $best->score, 'seconds' => (int) $best->started_at->diffInSeconds($best->finished_at)] : null,
            'tasks' => self::SUBNET_TASKS, 'pass' => self::PASS,
        ];
    }

    private function subnetTask(string $type): array
    {
        $prefix = match ($type) {
            'hosts', 'mask' => random_int(17, 30),
            default => random_int(18, 29),
        };
        $ip = $this->randomIp();
        $mask = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        $network = $ip & $mask;
        $broadcast = $network | (~$mask & 0xFFFFFFFF);
        $block = 2 ** (32 - $prefix);
        $hosts = $block - 2;
        $n = fn (int $v) => long2ip($v);
        $cidr = $n($ip).'/'.$prefix;
        $why = "Префикс /$prefix: маска ".$n($mask).", блок $block адресов. Сеть ".$n($network).', broadcast '.$n($broadcast).'.';

        return match ($type) {
            'network' => ['prompt' => "Адрес сети для узла $cidr", 'format' => 'например 10.0.0.0', 'answer' => $n($network), 'accept' => [$n($network)], 'explain' => $why],
            'broadcast' => ['prompt' => "Широковещательный адрес сети узла $cidr", 'format' => 'например 10.0.0.255', 'answer' => $n($broadcast), 'accept' => [$n($broadcast)], 'explain' => $why],
            'first' => ['prompt' => "Первый адрес узла (хоста) в сети $cidr", 'format' => 'IP-адрес', 'answer' => $n($network + 1), 'accept' => [$n($network + 1)], 'explain' => $why.' Первый узел — адрес сети + 1.'],
            'last' => ['prompt' => "Последний адрес узла (хоста) в сети $cidr", 'format' => 'IP-адрес', 'answer' => $n($broadcast - 1), 'accept' => [$n($broadcast - 1)], 'explain' => $why.' Последний узел — broadcast − 1.'],
            'hosts' => ['prompt' => "Сколько адресов можно назначить узлам в сети /$prefix?", 'format' => 'число', 'answer' => (string) $hosts, 'accept' => [(string) $hosts],
                'explain' => "2^(32−$prefix) = $block адресов, минус адрес сети и broadcast = $hosts."],
            'mask' => ['prompt' => "Маска /$prefix в десятичном виде", 'format' => 'например 255.255.255.0', 'answer' => $n($mask), 'accept' => [$n($mask)],
                'explain' => "$prefix единиц подряд: ".$n($mask).'.'],
            'prefix' => $this->prefixTask(),
            'same' => $this->sameTask($ip, $prefix, $network, $broadcast, $why),
        };
    }

    private function prefixTask(): array
    {
        $need = random_int(3, 2000);
        $bits = (int) ceil(log($need + 2, 2));
        $prefix = 32 - $bits;
        $mask = long2ip((0xFFFFFFFF << $bits) & 0xFFFFFFFF);

        return ['prompt' => "Самый длинный префикс (минимальная подсеть), в которой поместятся $need узлов", 'format' => 'например /24 или 255.255.255.0',
            'answer' => "/$prefix", 'accept' => ["/$prefix", (string) $prefix, $mask],
            'explain' => "Нужно $need + 2 (сеть и broadcast) = ".($need + 2).' адресов → 2^'.$bits.' = '.(2 ** $bits).", значит /$prefix ($mask)."];
    }

    private function sameTask(int $ip, int $prefix, int $network, int $broadcast, string $why): array
    {
        $inside = (bool) random_int(0, 1);
        $other = $inside ? random_int($network + 1, max($network + 1, $broadcast - 1)) : (random_int(0, 1) ? $broadcast + random_int(2, 40) : max(1, $network - random_int(2, 40)));
        $answer = $inside ? 'да' : 'нет';

        return ['prompt' => 'Находятся ли узлы '.long2ip($ip)."/$prefix и ".long2ip($other).' в одной сети?', 'format' => 'да или нет',
            'answer' => $answer, 'accept' => $inside ? ['да', 'yes'] : ['нет', 'no'], 'explain' => $why.' Адрес '.long2ip($other).($inside ? ' входит' : ' не входит').' в этот диапазон.'];
    }

    private function randomIp(): int
    {
        return match (random_int(0, 2)) {
            0 => ip2long('10.0.0.0') + random_int(0, 0xFFFFFF),
            1 => ip2long('172.16.0.0') + random_int(0, 0xFFFFF),
            default => ip2long('192.168.0.0') + random_int(0, 0xFFFF),
        };
    }

    private function normalizeAnswer(string $value): string
    {
        $v = mb_strtolower(str_replace([' ', ','], ['', '.'], trim($value)));

        return ltrim($v, '/');
    }

    // ---- Packet Tracer labs ----------------------------------------------------------------------------

    public function labsState(User $user): array
    {
        $subs = TrainingLabSubmission::where('user_id', $user->id)->with('reviewer:id,name')->orderByDesc('id')->get()->groupBy('lab_id');

        return array_map(fn ($lab) => $lab + [
            'submissions' => ($subs[$lab['id']] ?? collect())->map(fn ($s) => $this->presentSubmission($s))->values()->all(),
            'status' => ($subs[$lab['id']] ?? collect())->first()?->status,
            'accepted' => ($subs[$lab['id']] ?? collect())->contains('status', 'accepted'),
        ], $this->content()['packet_tracer']);
    }

    /** Submissions waiting for this reviewer and the latest reviewed ones. */
    public function reviewQueue(User $reviewer): array
    {
        if (!$this->canReview($reviewer)) {
            return [];
        }
        $roles = self::REVIEW_SCOPE[$reviewer->role];

        return TrainingLabSubmission::with(['user:id,name,role', 'reviewer:id,name'])
            ->whereHas('user', fn ($q) => $q->whereIn('role', $roles)->where('active', true))->where('user_id', '!=', $reviewer->id)
            ->orderByRaw("case when status = 'submitted' then 0 else 1 end")->orderByDesc('id')->limit(50)->get()
            ->map(fn ($s) => $this->presentSubmission($s) + ['user' => ['id' => $s->user->id, 'name' => $s->user->name], 'lab_title' => $this->lab($s->lab_id)['title'] ?? $s->lab_id])
            ->all();
    }

    public function presentSubmission(TrainingLabSubmission $s): array
    {
        return [
            'id' => $s->id, 'lab_id' => $s->lab_id, 'comment' => $s->comment, 'status' => $s->status, 'grade' => $s->grade,
            'review_comment' => $s->review_comment, 'reviewer' => $s->reviewer?->name, 'reviewed_at' => $s->reviewed_at?->toIso8601String(),
            'created_at' => $s->created_at->toIso8601String(),
            'files' => array_map(fn ($f, $i) => ['n' => $i, 'name' => $f['name'], 'size' => $f['size']], $s->files, array_keys($s->files)),
        ];
    }

    /** Practice summary for the training page and the team overview. */
    public function summarize(Collection $attempts, Collection $submissions): array
    {
        $ios = $attempts->where('kind', 'ios');
        $subnet = $attempts->where('kind', 'subnet')->whereNotNull('finished_at');

        return [
            'subnet_best' => $subnet->max('score'), 'subnet_sessions' => $subnet->count(),
            'ios_passed' => $ios->where('passed', true)->pluck('subject')->unique()->count(), 'ios_total' => count($this->content()['ios']),
            'labs_accepted' => $submissions->where('status', 'accepted')->pluck('lab_id')->unique()->count(), 'labs_total' => count($this->content()['packet_tracer']),
            'labs_pending' => $submissions->where('status', 'submitted')->count(),
            'labs_grade' => $submissions->where('status', 'accepted')->count() ? (int) round($submissions->where('status', 'accepted')->avg('grade')) : null,
        ];
    }

    public function summary(User $user): ?array
    {
        if (!$this->allows($user)) {
            return null;
        }

        return $this->summarize(TrainingLabAttempt::where('user_id', $user->id)->get(), TrainingLabSubmission::where('user_id', $user->id)->get());
    }

    public function teamRows(Collection $userIds): Collection
    {
        $attempts = TrainingLabAttempt::whereIn('user_id', $userIds)->get()->groupBy('user_id');
        $subs = TrainingLabSubmission::whereIn('user_id', $userIds)->get()->groupBy('user_id');

        return $userIds->mapWithKeys(fn ($id) => [$id => $this->summarize($attempts[$id] ?? collect(), $subs[$id] ?? collect())]);
    }
}
