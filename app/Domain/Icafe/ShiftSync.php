<?php

namespace App\Domain\Icafe;

use App\Models\Club;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Copies iCafeCloud cash shifts into icafe_shifts (club time zone → UTC). */
final class ShiftSync
{
    public function __construct(private Client $client) {}

    /** @return int shifts written */
    public function sync(Club $club, ?int $days = null): int
    {
        if (! $club->icafe_license || ! $club->icafe_token) return 0;
        $tz = $club->timezone ?: 'Asia/Tashkent';
        // First sync takes five weeks so cash control has a baseline right away.
        $days ??= DB::table('icafe_shifts')->where('club_id', $club->id)->exists() ? 3 : 35;
        $now = CarbonImmutable::now($tz);
        $rows = $this->client->get($club->icafe_license, $club->icafe_token, 'reports/shiftList', [
            'date_start' => $now->subDays($days)->toDateString(), 'date_end' => $now->toDateString(),
            'time_start' => '00:00', 'time_end' => '23:59', 'shift_staff_name' => 'all']);
        $at = fn (?string $s) => $s && $s !== '-' ? CarbonImmutable::parse($s, $tz)->utc() : null;
        $out = [];
        foreach ($rows as $r) {
            if (! isset($r['shift_id'], $r['shift_start_time'])) continue;
            $out[] = ['club_id' => $club->id, 'shift_id' => (string) $r['shift_id'], 'operator' => mb_substr((string) ($r['shift_staff_name'] ?? '—'), 0, 120),
                'started_at' => $at($r['shift_start_time']), 'ended_at' => $at($r['shift_end_time'] ?? null),
                'total' => (float) ($r['total_amount'] ?? 0), 'cash' => (float) ($r['cash'] ?? 0),
                'card' => (float) ($r['credit_card'] ?? 0), 'qr' => (float) ($r['qr'] ?? 0), 'synced_at' => now()];
        }
        if ($out) DB::table('icafe_shifts')->upsert($out, ['club_id', 'shift_id'], ['operator', 'started_at', 'ended_at', 'total', 'cash', 'card', 'qr', 'synced_at']);
        return count($out);
    }

    /** Refund amount of one shift from shiftDetail; null when iCafeCloud doesn't say. */
    public function refund(Club $club, string $shiftId): ?float
    {
        $r = $this->client->get($club->icafe_license, $club->icafe_token, 'reports/shiftDetail/'.$shiftId);
        return isset($r['cash_refund']) && is_numeric($r['cash_refund']) ? abs((float) $r['cash_refund']) : null;
    }
}
