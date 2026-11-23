<?php

namespace App\Domain\Icafe;

use App\Models\{ApiClient, Club};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Cache, Log};

/**
 * Live PC list of a club, kept warm in the background so the external API answers from memory
 * instead of waiting ~0.5 s for two iCafeCloud calls on every request.
 */
class PcSnapshot
{
    /** A snapshot older than this is refreshed live by the request itself (background refresh stalled). */
    public const MAX_AGE = 45;

    public function __construct(private Client $icafe) {}

    public static function key(int $clubId): string { return 'ext:pcs:'.$clubId; }

    /** @return array{pcs: list<array>, online: list<string>, fetched_at: string} */
    public function get(Club $club): array
    {
        $s = Cache::get(self::key($club->id));
        if (is_array($s) && CarbonImmutable::parse($s['fetched_at'])->diffInSeconds(now(), true) <= self::MAX_AGE) return $s;
        return $this->fetch($club);
    }

    public function fetch(Club $club): array
    {
        $pcs = array_values(array_filter($this->icafe->get($club->icafe_license, $club->icafe_token, 'pcs'),
            fn ($p) => (int) ($p['pc_icafe_id'] ?? 0) === (int) $club->icafe_license));
        $online = collect($this->icafe->get($club->icafe_license, $club->icafe_token, 'onlinePcList'))
            ->filter(fn ($p) => (int) ($p['is_connected'] ?? 0) === 1)->pluck('pc_name')->values()->all();
        $s = ['pcs' => $pcs, 'online' => $online, 'fetched_at' => now()->toIso8601String()];
        Cache::put(self::key($club->id), $s, 120);
        return $s;
    }

    /** Refreshes only clubs that have an active API client allowed to read PCs. */
    public function refreshActive(): void
    {
        $ids = ApiClient::whereNull('revoked_at')->get(['club_id', 'scopes'])
            ->filter(fn ($c) => in_array('pcs', (array) $c->scopes, true))->pluck('club_id')->unique();
        foreach (Club::whereIn('id', $ids)->whereNotNull('icafe_license')->whereNotNull('icafe_token')->get() as $club) {
            try { $this->fetch($club); } catch (\Throwable $e) { Log::warning('pc snapshot failed', ['club' => $club->id, 'error' => $e->getMessage()]); }
        }
    }
}
