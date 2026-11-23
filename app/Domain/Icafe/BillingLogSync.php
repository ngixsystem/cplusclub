<?php

namespace App\Domain\Icafe;

use App\Models\Club;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Copies iCafeCloud billing logs into icafe_billing_logs.
 *
 * iCafeCloud facts this relies on (checked against the live API):
 *  - `billingLogs` filters only with date_start/date_end AND time_start/time_end, as one
 *    continuous local-time range; pages hold 30 events, newest first, each with a unique log_id;
 *  - it keeps roughly six weeks of history.
 * Live: read the newest pages until a known log_id appears. Backfill: walk back one local day
 * at a time, at most PAGES_PER_RUN pages per run, remembering day and page.
 */
final class BillingLogSync
{
    public const PAGES_PER_RUN = 25;
    public const LIVE_MAX_PAGES = 20;
    public const MAX_DAYS_BACK = 120;
    public const EMPTY_DAYS_TO_STOP = 3;

    public function __construct(private Client $client) {}

    /** New events since the newest one we have. Returns how many were stored. */
    public function live(Club $club): int
    {
        if (! $club->icafe_license || ! $club->icafe_token) return 0;
        $tz = $club->timezone ?: 'Asia/Tashkent';
        $known = (int) DB::table('icafe_billing_logs')->where('club_id', $club->id)->max('log_id');
        $now = CarbonImmutable::now($tz);
        // Without history yet, the backfill brings the rest of today: start with the last hour.
        $from = $known ? $now->subDays(2) : $now->subHour();
        $stored = 0;
        for ($page = 1; $page <= self::LIVE_MAX_PAGES; $page++) {
            [$rows, $pages] = $this->page($club, $from, $now->addMinutes(2), $page);
            $stored += $this->store($club, $rows);
            if (! $rows || $page >= $pages) break;
            if ($known && min(array_map(fn ($r) => (int) $r['log_id'], $rows)) <= $known) break;
        }
        $this->state($club, ['live_at' => now(), 'last_error' => null]);
        return $stored;
    }

    /** One slice of history. Returns how many events were stored; 0 with done=true when finished. */
    public function backfill(Club $club): array
    {
        if (! $club->icafe_license || ! $club->icafe_token) return ['stored' => 0, 'done' => true];
        $tz = $club->timezone ?: 'Asia/Tashkent';
        $s = $this->stateRow($club);
        if ($s->backfill_done_at) return ['stored' => 0, 'done' => true];
        $today = CarbonImmutable::now($tz)->startOfDay();
        $day = $s->backfill_day ? CarbonImmutable::parse($s->backfill_day, $tz) : $today;
        $page = (int) $s->backfill_page ?: 1;
        $empty = (int) $s->empty_days;
        $oldest = $s->oldest_day;
        $stored = 0;
        for ($n = 0; $n < self::PAGES_PER_RUN; $n++) {
            [$rows, $pages] = $this->page($club, $day, $day->addDay(), $page);
            $stored += $this->store($club, $rows);
            if ($rows) { $empty = 0; $oldest = $day->toDateString(); }
            if ($rows && $page < $pages) { $page++; continue; }
            // The day is complete: next one back.
            if (! $rows && $page === 1) $empty++;
            $day = $day->subDay(); $page = 1;
            if ($empty >= self::EMPTY_DAYS_TO_STOP || $day->lessThan($today->subDays(self::MAX_DAYS_BACK))) {
                $this->state($club, ['backfill_day' => $day->toDateString(), 'backfill_page' => 1, 'empty_days' => $empty, 'oldest_day' => $oldest, 'backfill_done_at' => now(), 'last_error' => null]);
                return ['stored' => $stored, 'done' => true];
            }
        }
        $this->state($club, ['backfill_day' => $day->toDateString(), 'backfill_page' => $page, 'empty_days' => $empty, 'oldest_day' => $oldest, 'last_error' => null]);
        return ['stored' => $stored, 'done' => false];
    }

    public function fail(Club $club, string $message): void
    {
        $this->state($club, ['last_error' => mb_substr($message, 0, 200)]);
    }

    /** @return array{0: list<array>, 1: int} events of one page and the page count */
    private function page(Club $club, CarbonImmutable $from, CarbonImmutable $to, int $page): array
    {
        $b = $this->client->get($club->icafe_license, $club->icafe_token, 'billingLogs', [
            'date_start' => $from->toDateString(), 'time_start' => $from->format('H:i'),
            'date_end' => $to->toDateString(), 'time_end' => $to->format('H:i'), 'page' => $page]);
        $rows = array_values(array_filter($b['log_list'] ?? [], fn ($r) => isset($r['log_id'], $r['log_date'], $r['log_event'])
            && (! isset($r['log_icafe_id']) || (int) $r['log_icafe_id'] === (int) $club->icafe_license)));
        return [$rows, max(1, (int) ($b['paging_info']['pages'] ?? 1))];
    }

    private function store(Club $club, array $rows): int
    {
        if (! $rows) return 0;
        $num = fn ($v) => is_numeric($v) && abs((float) $v) < 1e11 ? (float) $v : 0.0;
        $str = fn ($v, $max) => ($v = trim((string) ($v ?? ''))) === '' ? null : mb_substr($v, 0, $max);
        $now = now();
        $out = array_map(fn ($r) => [
            'club_id' => $club->id, 'log_id' => (int) $r['log_id'],
            'occurred_at' => CarbonImmutable::parse($r['log_date'], 'UTC'),   // log_date is UTC, log_date_local the club's time
            'event' => mb_substr((string) $r['log_event'], 0, 40),
            'member_account' => $str($r['log_member_account'] ?? null, 120), 'pc_name' => $str($r['log_pc_name'] ?? null, 60),
            'money' => $num($r['log_money'] ?? 0), 'spend' => $num($r['log_spend'] ?? 0), 'card' => $num($r['log_card'] ?? 0),
            'bonus' => $num($r['log_bonus'] ?? 0), 'coin' => $num($r['log_coin'] ?? 0),
            'used_seconds' => self::seconds($r['log_used_secs'] ?? null),
            'details' => $str($r['log_details'] ?? null, 2000), 'staff_name' => $str($r['log_staff_name'] ?? null, 120),
            'package' => $str($r['pkg_name'] ?? null, 160), 'created_at' => $now,
        ], $rows);
        return DB::table('icafe_billing_logs')->insertOrIgnore($out);
    }

    /** "00:05:00" or "1186:46:52" → seconds. */
    public static function seconds(mixed $v): ?int
    {
        if (is_numeric($v)) return (int) $v;
        if (! is_string($v) || ! preg_match('/^(\d+):(\d{2}):(\d{2})$/', trim($v), $m)) return null;
        return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
    }

    private function stateRow(Club $club): object
    {
        DB::table('icafe_billing_sync')->insertOrIgnore(['club_id' => $club->id]);
        return DB::table('icafe_billing_sync')->where('club_id', $club->id)->first();
    }

    private function state(Club $club, array $values): void
    {
        $this->stateRow($club);
        DB::table('icafe_billing_sync')->where('club_id', $club->id)->update($values);
    }
}
