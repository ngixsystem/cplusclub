<?php

namespace App\Domain\Monitoring;

use App\Domain\Icafe\Client;
use App\Models\Club;
use Illuminate\Support\Facades\{Cache, DB};

/**
 * CPU/GPU temperature, CPU load and RAM from iCafeCloud Boot (CCBoot clients), the same
 * data the "Boot" tab of the iCafeCloud admin shows. Readings go into telemetry_samples,
 * so the monitoring board, thresholds and alerts treat them like agent samples.
 *
 * Only the pcs_status Boot command is used: it carries hardware readings and no member data.
 */
final class IcafeBootTelemetry
{
    private const METRICS = ['cpu_temp', 'gpu_temp', 'cpu_load', 'gpu_load', 'ram_used_percent', 'disk_free_percent'];

    public function __construct(private Client $client, private EvaluateSample $evaluator) {}

    /** @return array{matched:int,unmatched:int,supported:bool} */
    public function collect(Club $club): array
    {
        if (! $club->icafe_license || ! $club->icafe_token) return ['matched' => 0, 'unmatched' => 0, 'supported' => false];
        $skip = 'icafe-boot:unsupported:'.$club->id;
        if (Cache::has($skip)) return ['matched' => 0, 'unmatched' => 0, 'supported' => false];

        $command = json_encode(['action' => 'pcs_status', 'target' => 'boot', 'type' => 'request', 'from' => 'web', 'version' => 2, 'data' => []]);
        $reply = $this->client->post($club->icafe_license, $club->icafe_token, 'pcSessions/sendWssCommand', ['command' => $command]);
        $pcs = $reply['message']['data']['pcs'] ?? null;
        if ((int) ($reply['result'] ?? 0) !== 1 || ! is_array($pcs)) {
            // The club does not run iCafeCloud Boot (or its server is offline): back off for a while.
            Cache::put($skip, true, now()->addMinutes(10));
            return ['matched' => 0, 'unmatched' => 0, 'supported' => false];
        }

        $boot = $this->bootComputers($club);
        $equipment = DB::table('equipment')->where('club_id', $club->id)->where('type', 'pc')->get(['id', 'name', 'icafe_pc_name', 'mac']);
        $names = $equipment->pluck('name', 'id');
        $byName = []; $byMac = [];
        foreach ($equipment as $e) {
            foreach ([$e->icafe_pc_name, $e->name] as $n) if ($n !== null && $n !== '') $byName[mb_strtolower(trim($n))] ??= $e->id;
            if ($e->mac) $byMac[self::mac($e->mac)] = $e->id;
        }

        $now = now()->utc(); $matched = 0; $unmatched = 0;
        foreach ($pcs as $pc) {
            $meta = $boot[(int) ($pc['id'] ?? 0)] ?? null;
            $id = $meta ? ($byName[mb_strtolower(trim($meta['name']))] ?? ($meta['mac'] !== '' ? ($byMac[$meta['mac']] ?? null) : null)) : null;
            if (! $id) { $unmatched++; continue; }
            $sample = self::sample($pc) + ['observed_at' => $now->toIso8601String()];
            $link = self::linkMbps($pc['linkspeed'] ?? null);
            DB::transaction(function () use ($club, $id, $sample, $now, $link, $names) {
                $eq = DB::table('equipment')->where('id', $id)->lockForUpdate()->first(['id', 'link_expected_mbps']);
                $expected = $link ? max(1000, (int) $eq->link_expected_mbps, $link) : $eq->link_expected_mbps;
                DB::table('equipment')->where('id', $id)->update(['last_seen_at' => now(), 'link_expected_mbps' => $expected]);
                $row = ['equipment_id' => $id, 'observed_at' => $now, 'received_at' => now(), 'link_mbps' => $link,
                    'sensor_status' => json_encode($sample['sensor_status'], JSON_THROW_ON_ERROR)];
                foreach (self::METRICS as $m) $row[$m] = $sample[$m];
                DB::table('telemetry_samples')->insert($row);
                $this->evaluator->execute($club->id, $id, $sample);
                if ($link) $this->evaluateLink($club->id, $id, (string) ($names[$id] ?? '#'.$id), $link, (int) $expected, $now);
            });
            $matched++;
        }
        return ['matched' => $matched, 'unmatched' => $unmatched, 'supported' => true];
    }

    /** Boot client id => name and MAC. The list changes rarely, so it is cached. */
    private function bootComputers(Club $club): array
    {
        return Cache::remember('icafe-boot:pcs:'.$club->id.':'.hash('sha256', $club->icafe_license.'|'.$club->icafe_token), now()->addMinutes(10), function () use ($club) {
            $out = [];
            foreach ($this->client->get($club->icafe_license, $club->icafe_token, 'bootPcs') as $b) {
                if ((int) ($b['bpc_icafe_id'] ?? $club->icafe_license) !== (int) $club->icafe_license || ! isset($b['bpc_id'], $b['bpc_name'])) continue;
                $out[(int) $b['bpc_id']] = ['name' => mb_substr((string) $b['bpc_name'], 0, 255), 'mac' => self::mac((string) ($b['bpc_mac'] ?? ''))];
            }
            return $out;
        });
    }

    /** Converts one Boot reading ("62", "39%%") into a validated telemetry sample. */
    public static function sample(array $pc): array
    {
        $num = function ($v, float $max): ?float {
            if ($v === null || $v === '') return null;
            $v = rtrim(trim((string) $v), '%');
            if (! is_numeric($v)) return null;
            $f = (float) $v;
            return $f > 0 && $f <= $max ? round($f, 2) : null;
        };
        $s = ['cpu_temp' => $num($pc['cpu_temp'] ?? null, 150), 'gpu_temp' => $num($pc['gpu_temp'] ?? null, 150),
            'cpu_load' => $num($pc['cpu_usage'] ?? null, 100), 'gpu_load' => null,
            'ram_used_percent' => $num($pc['ram_usage'] ?? null, 100), 'disk_free_percent' => null];
        // A 0 % load is a real reading, unlike a 0 °C temperature.
        foreach (['cpu_load' => 'cpu_usage', 'ram_used_percent' => 'ram_usage'] as $m => $k) {
            if ($s[$m] === null && isset($pc[$k]) && rtrim(trim((string) $pc[$k]), '%') === '0') $s[$m] = 0.0;
        }
        $status = [];
        foreach (self::METRICS as $m) $status[$m] = in_array($m, ['gpu_load', 'disk_free_percent'], true) ? 'unsupported' : ($s[$m] === null ? 'unavailable' : 'ok');
        return $s + ['sensor_status' => $status];
    }

    /** "1000M" => 1000, "100M" => 100, "2.5G" => 2500; null when unknown. */
    public static function linkMbps($raw): ?int
    {
        if (! is_string($raw) && ! is_numeric($raw)) return null;
        if (! preg_match('/^\s*(\d+(?:\.\d+)?)\s*([MG])?/i', (string) $raw, $m)) return null;
        $mbps = (int) round((float) $m[1] * (strtoupper($m[2] ?? 'M') === 'G' ? 1000 : 1));
        return $mbps >= 10 && $mbps <= 100000 ? $mbps : null;
    }

    /**
     * Link-speed alert: opens when the negotiated speed stays below the expected one
     * (1000 Mbit/s, or the highest seen) for two polls in a row, recovers when it is back.
     */
    private function evaluateLink(int $clubId, int $equipmentId, string $name, int $link, int $expected, \DateTimeInterface $now): void
    {
        $when = \Carbon\CarbonImmutable::instance($now);
        DB::table('alerts')->insertOrIgnore(['club_id' => $clubId, 'equipment_id' => $equipmentId, 'metric' => 'link_speed']);
        $alert = DB::table('alerts')->where('equipment_id', $equipmentId)->where('metric', 'link_speed')->lockForUpdate()->first();
        if ($alert->last_sample_at && $when->lessThanOrEqualTo(\Carbon\CarbonImmutable::parse($alert->last_sample_at))) return;
        $maintenance = DB::table('maintenance_windows')->where('club_id', $clubId)->where('starts_at', '<=', $when)->where('ends_at', '>=', $when)->exists();
        $gap = $alert->last_sample_at && \Carbon\CarbonImmutable::parse($alert->last_sample_at)->diffInSeconds($when) > 180;
        $update = ['last_sample_at' => $when];
        $payload = ['equipment_id' => $equipmentId, 'metric' => 'link_speed', 'value' => $link, 'expected' => $expected];
        if ($link < $expected && ! $maintenance) {
            if ($alert->state !== 'active') {
                $since = (! $alert->breach_since || $gap) ? $when : \Carbon\CarbonImmutable::parse($alert->breach_since);
                $update['breach_since'] = $since;
                if ($since->diffInSeconds($when) >= 50) {
                    $update += ['state' => 'active', 'episode' => $alert->episode + 1, 'opened_at' => $when, 'recovered_at' => null];
                    $this->evaluator->event($clubId, 'monitor_problem', 'alert:'.$alert->id.':'.($alert->episode + 1).':open',
                        $payload + ['message' => "{$name}: сетевой линк упал до {$link} Мбит/с (норма {$expected} Мбит/с)"]);
                }
            }
        } else {
            $update['breach_since'] = null;
            if ($alert->state === 'active' && $link >= $expected) {
                $update += ['state' => 'normal', 'recovered_at' => $when];
                $this->evaluator->event($clubId, 'monitor_recovered', 'alert:'.$alert->id.':'.$alert->episode.':recovered',
                    $payload + ['message' => "{$name}: сетевой линк восстановлен, {$link} Мбит/с"]);
            }
        }
        DB::table('alerts')->where('id', $alert->id)->update($update);
    }

    private static function mac(string $mac): string
    {
        return strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac));
    }
}
