<?php

namespace App\Http\Controllers;

use App\Domain\Access\ClubAccess;
use App\Domain\Monitoring\LatencyMonitor;
use App\Models\Club;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};
use Inertia\Inertia;

/** Game-server ping: agent API (targets, results) and the admin page with CSV for the ISP. */
class LatencyController extends Controller
{
    private const HOST = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$|^\d{1,3}(?:\.\d{1,3}){3}$/i';

    private function agent(Request $r): object
    {
        $token = $r->bearerToken();
        abort_unless($token && strlen($token) <= 256, 401);
        $agent = DB::table('agents')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
        abort_unless($agent, 401);
        return $agent;
    }

    public function targets(Request $r)
    {
        $agent = $this->agent($r);
        return response()->json(['interval_seconds' => 60, 'targets' => DB::table('latency_targets')->where('club_id', $agent->club_id)->where('enabled', true)
            ->orderBy('id')->limit(10)->get(['id', 'host', 'port'])]);
    }

    public function ingest(Request $r, LatencyMonitor $monitor)
    {
        abort_if(strlen($r->getContent()) > 32768, 413);
        $agent = $this->agent($r);
        $d = $r->validate(['batch_id' => 'required|uuid', 'observed_at' => 'required|date', 'results' => 'required|array|min:1|max:10',
            'results.*.target_id' => 'required|integer|distinct', 'results.*.sent' => 'required|integer|between:1,50',
            'results.*.received' => 'required|integer|between:0,50',
            'results.*.rtt_avg_ms' => 'present|nullable|numeric|between:0,10000', 'results.*.rtt_max_ms' => 'present|nullable|numeric|between:0,10000',
            'results.*.jitter_ms' => 'nullable|numeric|between:0,10000']);
        foreach ($d['results'] as $x) abort_if($x['received'] > $x['sent'], 422, 'Получено больше пакетов, чем отправлено.');
        $when = CarbonImmutable::parse($d['observed_at'])->utc();
        abort_if($when->isAfter(now()->addMinute()) || $when->isBefore(now()->subMinutes(10)), 422, 'Время пакета вне допустимого окна.');
        $duplicate = DB::transaction(function () use ($agent, $d, $when, $monitor) {
            if (! DB::table('telemetry_batches')->insertOrIgnore(['agent_id' => $agent->id, 'batch_id' => $d['batch_id'], 'received_at' => now()])) return true;
            $club = DB::table('clubs')->where('id', $agent->club_id)->value('name');
            foreach ($d['results'] as $s) {
                $t = DB::table('latency_targets')->where('id', $s['target_id'])->where('club_id', $agent->club_id)->lockForUpdate()->first();
                abort_unless($t, 403);
                $s += ['jitter_ms' => null];
                if ($s['received'] === 0) $s['rtt_avg_ms'] = $s['rtt_max_ms'] = null;
                DB::table('latency_samples')->insert(['target_id' => $t->id, 'agent_id' => $agent->id, 'observed_at' => $when, 'sent' => $s['sent'],
                    'received' => $s['received'], 'rtt_avg_ms' => $s['rtt_avg_ms'], 'rtt_max_ms' => $s['rtt_max_ms'], 'jitter_ms' => $s['jitter_ms']]);
                if ($t->enabled) $monitor->evaluate($t, (string) $club, $s);
            }
            DB::table('agents')->where('id', $agent->id)->update(['last_seen_at' => now()]);
            return false;
        });
        return response()->json(['accepted' => true, 'duplicate' => $duplicate]);
    }

    /** @return array{0:Club,1:\Illuminate\Support\Collection} */
    private function club(Request $r): array
    {
        abort_unless($r->user()->active, 403);
        $clubs = ClubAccess::filter(Club::query(), $r->user(), 'id')->orderBy('name')->get(['id', 'name', 'timezone']);
        abort_if($clubs->isEmpty(), 403);
        $club = $r->filled('club_id') ? $clubs->firstWhere('id', (int) $r->input('club_id')) : $clubs->first();
        abort_unless($club, 403);
        return [$club, $clubs];
    }

    public function index(Request $r)
    {
        [$club, $clubs] = $this->club($r);
        $days = in_array((int) $r->input('days'), [7, 30], true) ? (int) $r->input('days') : 7;
        $tz = $club->timezone ?: 'Asia/Tashkent';
        $targets = DB::table('latency_targets')->where('club_id', $club->id)->orderBy('id')->get();
        $since = now($tz)->startOfDay()->subDays($days - 1)->utc();
        $daily = DB::table('latency_samples')->whereIn('target_id', $targets->pluck('id'))->where('observed_at', '>=', $since)
            ->selectRaw("target_id, to_char(observed_at AT TIME ZONE ?, 'YYYY-MM-DD') as day, round(avg(rtt_avg_ms)::numeric, 1) as rtt, round((100.0 * (sum(sent) - sum(received)) / nullif(sum(sent), 0))::numeric, 2) as loss", [$tz])
            ->groupBy('target_id', 'day')->get();
        $labels = collect(range($days - 1, 0))->map(fn ($k) => now($tz)->subDays($k)->toDateString())->values();
        $series = fn (string $field) => $targets->map(fn ($t) => ['key' => 't'.$t->id, 'label' => $t->name,
            'values' => $labels->map(fn ($day) => (float) ($daily->first(fn ($d) => $d->target_id === $t->id && $d->day === $day)?->$field ?? 0))->all()])->values();
        $recent = DB::table('latency_samples')->whereIn('target_id', $targets->pluck('id'))->where('observed_at', '>=', now()->subDay())
            ->selectRaw('target_id, count(*) as n, round(avg(rtt_avg_ms)::numeric, 1) as avg, round(max(rtt_max_ms)::numeric, 1) as max, round((100.0 * (sum(sent) - sum(received)) / nullif(sum(sent), 0))::numeric, 2) as loss, max(observed_at) as last')
            ->groupBy('target_id')->get()->keyBy('target_id');
        return Inertia::render('Latency', [
            'club' => ['id' => $club->id, 'name' => $club->name], 'clubs' => $clubs->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'days' => $days, 'labels' => $labels, 'rtt' => $series('rtt'), 'loss' => $series('loss'),
            'targets' => $targets->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'host' => $t->host, 'port' => $t->port, 'max_rtt_ms' => $t->max_rtt_ms,
                'max_loss_pct' => $t->max_loss_pct, 'enabled' => $t->enabled, 'state' => $t->state, 'recent' => $recent[$t->id] ?? null]),
            'agents' => DB::table('agents')->where('club_id', $club->id)->whereNull('revoked_at')->count(),
            'canManage' => ClubAccess::allClubs($r->user()),
        ]);
    }

    public function store(Request $r)
    {
        abort_unless($r->user()->active && ClubAccess::allClubs($r->user()), 403);
        $d = $r->validate(['club_id' => 'required|integer|exists:clubs,id', 'name' => 'required|string|max:80', 'host' => ['required', 'string', 'max:253', 'regex:'.self::HOST],
            'port' => 'nullable|integer|between:1,65535', 'max_rtt_ms' => 'required|integer|between:5,1000', 'max_loss_pct' => 'required|integer|between:0,50']);
        Gate::authorize('view', Club::findOrFail($d['club_id']));
        abort_if(DB::table('latency_targets')->where('club_id', $d['club_id'])->count() >= 10, 422, 'Не более 10 серверов на клуб.');
        DB::table('latency_targets')->insertOrIgnore($d + ['host' => strtolower($d['host']), 'created_at' => now(), 'updated_at' => now()]);
        return back()->with('success', 'Сервер добавлен. Агент начнёт измерять его в течение пяти минут.');
    }

    public function update(Request $r, int $target)
    {
        abort_unless($r->user()->active && ClubAccess::allClubs($r->user()), 403);
        $t = DB::table('latency_targets')->where('id', $target)->first();
        abort_unless($t, 404);
        Gate::authorize('view', Club::findOrFail($t->club_id));
        if ($r->boolean('delete')) { DB::table('latency_targets')->where('id', $t->id)->delete(); return back()->with('success', 'Сервер удалён.'); }
        $d = $r->validate(['enabled' => 'required|boolean', 'max_rtt_ms' => 'required|integer|between:5,1000', 'max_loss_pct' => 'required|integer|between:0,50']);
        DB::table('latency_targets')->where('id', $t->id)->update($d + ['updated_at' => now()] + (! $d['enabled'] ? ['state' => 'normal', 'bad_streak' => 0, 'good_streak' => 0] : []));
        return back()->with('success', 'Сохранено.');
    }

    /** Hourly CSV for the internet provider. */
    public function export(Request $r)
    {
        [$club] = $this->club($r);
        $days = min(90, max(1, (int) $r->input('days', 30)));
        $tz = $club->timezone ?: 'Asia/Tashkent';
        $rows = DB::table('latency_samples as s')->join('latency_targets as t', 't.id', '=', 's.target_id')->where('t.club_id', $club->id)
            ->where('s.observed_at', '>=', now()->subDays($days))
            ->selectRaw("to_char(date_trunc('hour', s.observed_at AT TIME ZONE ?), 'YYYY-MM-DD HH24:00') as hour, t.name, t.host, count(*) as n, round(avg(s.rtt_avg_ms)::numeric, 1) as avg, round(max(s.rtt_max_ms)::numeric, 1) as max, round((100.0 * (sum(s.sent) - sum(s.received)) / nullif(sum(s.sent), 0))::numeric, 2) as loss", [$tz])
            ->groupBy('hour', 't.name', 't.host')->orderBy('hour')->orderBy('t.name')->get();
        $safe = fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'".$v : (string) $v;
        $name = 'latency-'.$club->id.'-'.now($tz)->format('Ymd').'.csv';
        return response()->streamDownload(function () use ($rows, $safe, $tz) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Час ('.$tz.')', 'Сервер', 'Адрес', 'Измерений', 'Пинг средний, мс', 'Пинг максимум, мс', 'Потери, %'], ';');
            foreach ($rows as $x) fputcsv($out, [$x->hour, $safe($x->name), $safe($x->host), $x->n, $x->avg, $x->max, $x->loss], ';');
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
