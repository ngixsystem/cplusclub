<?php

namespace App\Domain\Games;

use App\Domain\Access\ClubAccess;
use App\Models\{Club, Equipment};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Cache, DB};

/**
 * Games played in a club. Every Boot poll (once a minute) adds one minute per PC to the
 * game it is running; the live view reads the latest reading of each PC.
 */
final class GameUsage
{
    public const PERIODS = ['today', 'yesterday', 'week', 'last_week', 'month', 'all'];

    /** Adds this minute of play. A poll repeated within the same minute is not counted twice. */
    public static function record(int $clubId, array $pcs, \DateTimeInterface $now): void
    {
        $minute = CarbonImmutable::instance($now)->utc();
        if (! Cache::add('game-minute:'.$clubId.':'.$minute->format('YmdHi'), 1, 180)) return;
        $counts = []; $names = IcafeGames::exeMap($clubId);
        foreach ($pcs as $pc) {
            $g = GameCatalog::classify($pc['activeprocess'] ?? null, $names);
            if ($g['kind'] === 'game') $counts[$g['name']] = ($counts[$g['name']] ?? 0) + 1;
        }
        if (! $counts) return;
        $hour = $minute->startOfHour();
        foreach ($counts as $game => $n) {
            DB::statement('INSERT INTO game_usage_hourly (club_id, hour, game, minutes) VALUES (?, ?, ?, ?)
                ON CONFLICT (club_id, hour, game) DO UPDATE SET minutes = game_usage_hourly.minutes + EXCLUDED.minutes', [$clubId, $hour, $game, $n]);
        }
    }

    /** What every PC that reported in the last 3 minutes is running now. */
    public function live(Club $club): array
    {
        $pcs = Equipment::where('club_id', $club->id)->where('type', 'pc')->get(['id', 'name', 'zone'])->keyBy('id');
        $latest = DB::table('telemetry_samples')->whereIn('equipment_id', $pcs->keys())
            ->where('observed_at', '>=', now()->subSeconds(180))->where('observed_at', '<=', now()->addMinute())
            ->selectRaw('DISTINCT ON (equipment_id) equipment_id, active_process')
            ->orderBy('equipment_id')->orderByDesc('observed_at')->orderByDesc('id')->get();
        $games = []; $apps = 0; $idle = 0; $machines = []; $names = IcafeGames::exeMap($club->id);
        foreach ($latest as $s) {
            $e = $pcs[$s->equipment_id];
            $g = GameCatalog::classify($s->active_process, $names);
            $machines[] = ['id' => $e->id, 'name' => $e->name, 'zone' => $e->zone, 'kind' => $g['kind'], 'title' => $g['name']];
            if ($g['kind'] === 'game') $games[$g['name']][] = ['id' => $e->id, 'name' => $e->name, 'zone' => $e->zone];
            elseif ($g['kind'] === 'app') $apps++;
            else $idle++;
        }
        uasort($games, fn ($a, $b) => count($b) <=> count($a));
        usort($machines, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return [
            'on' => count($latest), 'total' => $pcs->count(), 'playing' => array_sum(array_map('count', $games)), 'apps' => $apps, 'idle' => $idle,
            'games' => collect($games)->map(fn ($list, $name) => ['name' => $name, 'count' => count($list),
                'pcs' => collect($list)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()])->values(),
            'machines' => $machines, 'updated_at' => now()->toIso8601String(),
        ];
    }

    /** Ranking for a period and the daily or weekly trend of the top games. */
    public function stats(Club $club, string $period, string $trend, string $metric = 'hours'): array
    {
        $tz = $club->timezone ?: 'Asia/Tashkent';
        $today = CarbonImmutable::now($tz)->startOfDay();
        [$from, $to, $label] = match ($period) {
            'yesterday' => [$today->subDay(), $today, 'вчера'],
            'week' => [$today->startOfWeek(), $today->addDay(), 'эта неделя'],
            'last_week' => [$today->startOfWeek()->subWeek(), $today->startOfWeek(), 'прошлая неделя'],
            'month', 'all' => [$today->subDays(29), $today->addDay(), '30 дней'],
            default => [$today, $today->addDay(), 'сегодня'],
        };
        if ($metric === 'launches') return $this->launches($club, $period, $from, $to, $label) + ['trend' => $this->trend($club, $tz, $today, $trend)];
        $rows = DB::table('game_usage_hourly')->where('club_id', $club->id)->where('hour', '>=', $from->utc())->where('hour', '<', $to->utc())
            ->select('game')->selectRaw('sum(minutes) as minutes')->groupBy('game')->orderByDesc('minutes')->limit(15)->get();
        $all = (int) DB::table('game_usage_hourly')->where('club_id', $club->id)->where('hour', '>=', $from->utc())->where('hour', '<', $to->utc())->sum('minutes');
        $top = $rows->map(fn ($r) => ['name' => $r->game, 'hours' => round($r->minutes / 60, 1), 'share' => $all ? (int) round($r->minutes * 100 / $all) : 0])->values()->all();

        return ['metric' => 'hours', 'period' => $period, 'period_label' => $label, 'total_hours' => round($all / 60, 1), 'top' => $top,
            'trend' => $this->trend($club, $tz, $today, $trend)];
    }

    /** Launches through the club menu, from iCafeCloud's counters (period) or its all-time total. */
    private function launches(Club $club, string $period, CarbonImmutable $from, CarbonImmutable $to, string $label): array
    {
        if ($period === 'all') {
            $rows = DB::table('icafe_games')->where('club_id', $club->id)->where('launches', '>', 0)->orderByDesc('launches')->limit(60)->get(['name', 'class', 'launches'])
                ->filter(fn ($g) => IcafeGames::isGame($g->class))->take(15)->values();
            $label = 'всё время';
        } else {
            $rows = DB::table('icafe_game_launches_daily as d')->join('icafe_games as g', fn ($j) => $j->on('g.club_id', '=', 'd.club_id')->on('g.pkg_id', '=', 'd.pkg_id'))
                ->where('d.club_id', $club->id)->where('d.day', '>=', $from->toDateString())->where('d.day', '<', $to->toDateString())
                ->select('d.name', 'g.class')->selectRaw('sum(d.launches) as launches')->groupBy('d.name', 'g.class')->orderByDesc('launches')->limit(60)->get()
                ->filter(fn ($g) => IcafeGames::isGame($g->class))->take(15)->values();
        }
        $all = (int) $rows->sum('launches');
        $since = DB::table('icafe_games')->where('club_id', $club->id)->min('synced_at');
        return ['metric' => 'launches', 'period' => $period, 'period_label' => $label, 'total_launches' => $all, 'total_hours' => 0,
            'launches_since' => DB::table('icafe_game_launches_daily')->where('club_id', $club->id)->min('day') ?? ($since ? CarbonImmutable::parse($since)->toDateString() : null),
            'top' => $rows->map(fn ($r) => ['name' => $r->name, 'launches' => (int) $r->launches, 'hours' => 0, 'share' => $all ? (int) round($r->launches * 100 / $all) : 0])->all()];
    }

    private function trend(Club $club, string $tz, CarbonImmutable $today, string $trend): array
    {
        // Trend: the five most played games over the trend window, in hours per day or per week.
        $weeks = $trend === 'weeks';
        $start = $weeks ? $today->startOfWeek()->subWeeks(7) : $today->subDays(13);
        $bucket = $weeks ? "date_trunc('week', hour AT TIME ZONE ?)" : "date_trunc('day', hour AT TIME ZONE ?)";
        $data = DB::table('game_usage_hourly')->where('club_id', $club->id)->where('hour', '>=', $start->utc())
            ->selectRaw("to_char($bucket, 'YYYY-MM-DD') as bucket, game, sum(minutes) as minutes", [$tz])->groupBy('bucket', 'game')->get();
        $leaders = $data->groupBy('game')->map(fn ($g) => $g->sum('minutes'))->sortDesc()->take(5)->keys();
        $labels = collect(range($weeks ? 7 : 13, 0))->map(fn ($k) => ($weeks ? $today->startOfWeek()->subWeeks($k) : $today->subDays($k))->toDateString())->values();
        $series = $leaders->map(fn ($game) => ['key' => md5($game), 'label' => $game, 'values' => $labels->map(fn ($b) => round(
            (int) ($data->first(fn ($d) => $d->game === $game && $d->bucket === $b)?->minutes ?? 0) / 60, 1))->all()])->values();
        // The leader of each day/week, for the table under the chart.
        $leadersBy = $labels->map(function ($b) use ($data) {
            $best = $data->where('bucket', $b)->sortByDesc('minutes')->first();
            return ['bucket' => $b, 'game' => $best?->game, 'hours' => $best ? round($best->minutes / 60, 1) : 0];
        })->values();

        return ['mode' => $weeks ? 'weeks' : 'days', 'labels' => $labels, 'series' => $series, 'leaders' => $leadersBy];
    }

    /** Clubs the user may see that report games (iCafeCloud connected). */
    public static function clubs($user)
    {
        return ClubAccess::filter(Club::query(), $user, 'id')->whereNotNull('icafe_license')->orderBy('name')->get(['id', 'name', 'timezone']);
    }
}
