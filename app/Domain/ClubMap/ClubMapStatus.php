<?php

namespace App\Domain\ClubMap;

use App\Domain\Icafe\Client;
use App\Models\Club;
use Illuminate\Support\Facades\Cache;

/**
 * Live state of a club's computers from iCafeCloud for the 3D map:
 * online = connected to iCafeCloud, busy = a session is running (pc_in_using).
 * Member data from the API is never returned. One upstream fetch per club per 15 s.
 */
final class ClubMapStatus
{
    private const FRESH_SECONDS = 15;

    public function __construct(private Client $client) {}

    /** Computer number from an iCafeCloud name such as "001", "PC-12" or "VIP 081". */
    public static function number(string $name): ?int
    {
        return preg_match_all('/\d+/', $name, $m) ? (int) end($m[0]) : null;
    }

    public function snapshot(Club $club): array
    {
        if (! $club->icafe_license || ! $club->icafe_token) {
            return ['connected' => false, 'stale' => false, 'updated_at' => null, 'error' => null, 'computers' => []];
        }
        $key = 'club-map:'.$club->id.':'.hash('sha256', $club->icafe_license.'|'.$club->icafe_token);
        $cached = Cache::get($key);
        if ($cached && time() - $cached['checked_at'] < self::FRESH_SECONDS) return $this->public($cached);

        $lock = Cache::lock($key.':lock', 20);
        if (! $lock->get()) {
            return $this->public($cached ?? ['connected' => true, 'stale' => true, 'updated_at' => null, 'error' => 'Получаем состояние ПК…', 'computers' => [], 'checked_at' => 0]);
        }
        try {
            $pcs = array_filter($this->client->get($club->icafe_license, $club->icafe_token, 'pcs'),
                fn ($p) => (int) ($p['pc_icafe_id'] ?? 0) === (int) $club->icafe_license && (int) ($p['pc_console_type'] ?? -1) === 0);
            $online = [];
            foreach ($this->client->get($club->icafe_license, $club->icafe_token, 'onlinePcList') as $row) {
                if (isset($row['pc_name']) && (int) ($row['is_connected'] ?? 0) === 1) $online[mb_strtolower(trim((string) $row['pc_name']))] = true;
            }
            $computers = [];
            foreach ($pcs as $pc) {
                $name = trim((string) ($pc['pc_name'] ?? ''));
                $number = self::number($name);
                if ($number === null) continue;
                $isOnline = isset($online[mb_strtolower($name)]);
                $computers[(string) $number] = [
                    'name' => mb_substr($name, 0, 40),
                    'area' => mb_substr((string) ($pc['pc_area_name'] ?? ''), 0, 40),
                    'online' => $isOnline,
                    'busy' => $isOnline && (bool) ($pc['pc_in_using'] ?? false),
                    'enabled' => (bool) ($pc['pc_enabled'] ?? true),
                ];
            }
            $data = ['connected' => true, 'stale' => false, 'updated_at' => now()->toIso8601String(), 'error' => null,
                'computers' => $computers, 'checked_at' => time()];
        } catch (\Throwable) {
            $data = [...($cached ?? ['computers' => [], 'updated_at' => null]), 'connected' => true, 'stale' => true,
                'error' => 'Нет свежей связи с iCafeCloud. Показано последнее известное состояние.', 'checked_at' => time()];
        } finally {
            $lock->release();
        }
        Cache::put($key, $data, now()->addHour());
        return $this->public($data);
    }

    private function public(array $data): array
    {
        unset($data['checked_at']);
        return $data;
    }
}
