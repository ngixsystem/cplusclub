<?php

namespace App\Domain\Tickets;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daily ticket flow for the charts: tickets created per day, tickets finished
 * (resolved or closed) per day, and how many were still open at the end of
 * each day. Days are counted in Tashkent time, like the rest of the admin.
 */
final class TicketStats
{
    public const TZ = 'Asia/Tashkent';
    public const RANGES = [7, 30, 90];

    /** When a ticket stopped being open; legacy rows without a timestamp fall back to updated_at. */
    private const DONE = "COALESCE(resolved_at, closed_at, CASE WHEN status IN ('resolved','closed') THEN updated_at END)";

    public static function build(Builder $tickets, int $days): array
    {
        $days = in_array($days, self::RANGES, true) ? $days : 30;
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $start = $today->subDays($days - 1);
        $startUtc = $start->utc();

        $perDay = fn (Builder $q, string $column) => $q->toBase()
            ->selectRaw("to_char(($column) AT TIME ZONE ?, 'YYYY-MM-DD') AS d, count(*) AS n", [self::TZ])
            ->groupBy('d')->pluck('n', 'd');

        $created = $perDay((clone $tickets)->where('created_at', '>=', $startUtc), 'created_at');
        $done = $perDay((clone $tickets)->whereRaw(self::DONE.' >= ?', [$startUtc]), self::DONE);
        // Still open when the range started.
        $openAtStart = (clone $tickets)->where('created_at', '<', $startUtc)
            ->where(fn ($q) => $q->whereRaw(self::DONE.' IS NULL')->orWhereRaw(self::DONE.' >= ?', [$startUtc]))
            ->count();
        $median = (clone $tickets)->toBase()->whereRaw(self::DONE.' >= ?', [$startUtc])
            ->selectRaw('percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM ('.self::DONE.' - created_at)) / 60) AS m')
            ->value('m');

        $labels = $new = $closed = $open = [];
        $level = $openAtStart;
        for ($d = $start; $d <= $today; $d = $d->addDay()) {
            $key = $d->format('Y-m-d');
            $n = (int) ($created[$key] ?? 0);
            $c = (int) ($done[$key] ?? 0);
            $level += $n - $c;
            $labels[] = $key;
            $new[] = $n;
            $closed[] = $c;
            $open[] = max(0, $level);
        }

        return [
            'range' => $days,
            'labels' => $labels,
            'series' => ['new' => $new, 'closed' => $closed, 'open' => $open],
            'totals' => [
                'new' => array_sum($new),
                'closed' => array_sum($closed),
                'open_now' => end($open) ?: 0,
                'open_start' => $openAtStart,
                'median_minutes' => $median === null ? null : (int) round((float) $median),
            ],
        ];
    }
}
