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
            DB::transaction(function () use ($club, $id, $sample, $now) {
                DB::table('equipment')->where('id', $id)->lockForUpdate()->update(['last_seen_at' => now()]);
                $row = ['equipment_id' => $id, 'observed_at' => $now, 'received_at' => now(), 'sensor_status' => json_encode($sample['sensor_status'], JSON_THROW_ON_ERROR)];
                foreach (self::METRICS as $m) $row[$m] = $sample[$m];
                DB::table('telemetry_samples')->insert($row);
                $this->evaluator->execute($club->id, $id, $sample);
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

    private static function mac(string $mac): string
    {
        return strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac));
    }
}
