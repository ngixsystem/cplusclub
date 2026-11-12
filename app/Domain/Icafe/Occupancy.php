<?php

namespace App\Domain\Icafe;

use App\Models\Club;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** How full the hall is: a sample every 5 minutes, summarised as a weekday × hour grid. */
final class Occupancy
{
    public function __construct(private Dashboard $dashboard) {}

    public function sample(Club $club): ?array
    {
        if (! $club->icafe_license || ! $club->icafe_token) return null;
        $snap = $this->dashboard->snapshot($club);
        if (! empty($snap['stale']) || ! isset($snap['computers'])) return null;   // never store an old picture as new
        $pcs = collect($snap['computers']);
        $row = ['club_id' => $club->id, 'sampled_at' => now()->startOfMinute()->subMinutes(now()->minute % 5),
            'busy' => $pcs->where('busy', true)->count(), 'online' => $pcs->where('online', true)->count(), 'total' => $pcs->count()];
        if (! $row['total']) return null;
        DB::table('hall_occupancy')->upsert([$row], ['club_id', 'sampled_at'], ['busy', 'online', 'total']);
        return $row;
    }

    /**
     * Average share of busy PCs (0–100) for each local weekday (1 = Mon) and hour over the last $days.
     *
     * @return array{grid: array<int, array<int, int|null>>, peak: ?array, quiet: ?array, days: int, samples: int}
     */
    public function heatmap(Club $club, int $days = 28): array
    {
        $tz = $club->timezone ?: 'Asia/Tashkent';
        $rows = DB::table('hall_occupancy')->where('club_id', $club->id)->where('sampled_at', '>=', now()->subDays($days))->where('total', '>', 0)
            ->selectRaw('extract(isodow from sampled_at at time zone ?)::int as dow, extract(hour from sampled_at at time zone ?)::int as hour', [$tz, $tz])
            ->selectRaw('round(avg(busy * 100.0 / total))::int as pct, count(*) as n')
            ->groupByRaw('1, 2')->get();
        $grid = [];
        for ($d = 1; $d <= 7; $d++) $grid[$d] = array_fill(0, 24, null);
        foreach ($rows as $r) $grid[$r->dow][$r->hour] = $r->pct;
        $cells = $rows->filter(fn ($r) => $r->n >= 3);
        $peak = $cells->sortByDesc('pct')->first(); $quiet = $cells->sortBy('pct')->first();
        $since = DB::table('hall_occupancy')->where('club_id', $club->id)->min('sampled_at');
        return ['grid' => $grid, 'samples' => (int) $rows->sum('n'),
            'peak' => $peak ? ['dow' => $peak->dow, 'hour' => $peak->hour, 'pct' => $peak->pct] : null,
            'quiet' => $quiet ? ['dow' => $quiet->dow, 'hour' => $quiet->hour, 'pct' => $quiet->pct] : null,
            'days' => $since ? min($days, (int) ceil(CarbonImmutable::parse($since)->diffInDays(now(), true))) : 0];
    }

    /** Average and peak occupancy for one local day. */
    public function day(Club $club, CarbonImmutable $localDay): ?array
    {
        $tz = $club->timezone ?: 'Asia/Tashkent';
        $from = $localDay->startOfDay()->utc(); $to = $localDay->startOfDay()->addDay()->utc();
        $q = DB::table('hall_occupancy')->where('club_id', $club->id)->where('sampled_at', '>=', $from)->where('sampled_at', '<', $to)->where('total', '>', 0);
        $avg = (clone $q)->selectRaw('round(avg(busy * 100.0 / total))::int as pct, count(*) as n')->first();
        if (! $avg || ! $avg->n) return null;
        $peak = (clone $q)->selectRaw('extract(hour from sampled_at at time zone ?)::int as hour, round(avg(busy * 100.0 / total))::int as pct', [$tz])
            ->groupByRaw('1')->orderByDesc('pct')->first();
        return ['avg' => (int) $avg->pct, 'peak_hour' => $peak?->hour, 'peak_pct' => $peak?->pct];
    }
}
