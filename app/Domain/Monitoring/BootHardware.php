<?php

namespace App\Domain\Monitoring;

use App\Domain\Icafe\Client;
use App\Models\Club;
use Illuminate\Support\Facades\DB;

/**
 * Hardware inventory from iCafeCloud Boot (bootPcs: bpc_cpu, bpc_mb, bpc_vga, bpc_ram,
 * bpc_nic_description). The first reading fills the PC passport quietly; later
 * differences are logged and raise a «hardware_changed» alert (a GPU swapped, RAM lost).
 */
final class BootHardware
{
    /** passport column => bootPcs field */
    public const FIELDS = ['cpu' => 'bpc_cpu', 'motherboard' => 'bpc_mb', 'gpu' => 'bpc_vga', 'ram_mb' => 'bpc_ram', 'nic' => 'bpc_nic_description'];
    public const LABELS = ['cpu' => 'Процессор', 'motherboard' => 'Материнская плата', 'gpu' => 'Видеокарта', 'ram_mb' => 'Оперативная память', 'nic' => 'Сетевая карта'];

    public function __construct(private Client $client, private EvaluateSample $events) {}

    /** @return array{matched:int,changed:int} */
    public function sync(Club $club): array
    {
        if (! $club->icafe_license || ! $club->icafe_token) return ['matched' => 0, 'changed' => 0];
        $rows = $this->client->get($club->icafe_license, $club->icafe_token, 'bootPcs');
        $equipment = DB::table('equipment')->where('club_id', $club->id)->where('type', 'pc')->get();
        $byName = []; $byMac = [];
        foreach ($equipment as $e) {
            foreach ([$e->icafe_pc_name ?? null, $e->name] as $n) if ($n !== null && $n !== '') $byName[mb_strtolower(trim($n))] ??= $e;
            if ($e->mac) $byMac[strtolower(preg_replace('/[^a-fA-F0-9]/', '', $e->mac))] = $e;
        }
        // One Boot entry per PC: an exact name match beats a MAC match, then the most recently
        // seen entry wins (a PC re-added in Boot keeps its old entry with old hardware).
        $pick = [];
        foreach ($rows as $b) {
            if ((int) ($b['bpc_icafe_id'] ?? $club->icafe_license) !== (int) $club->icafe_license || ! isset($b['bpc_name'])) continue;
            $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', (string) ($b['bpc_mac'] ?? '')));
            $byNameHit = $byName[mb_strtolower(trim((string) $b['bpc_name']))] ?? null;
            $e = $byNameHit ?? ($mac !== '' ? ($byMac[$mac] ?? null) : null);
            if (! $e) continue;
            $rank = [$byNameHit ? 1 : 0, (string) ($b['bpc_last_time'] ?? '')];
            if (! isset($pick[$e->id]) || $rank > $pick[$e->id][0]) $pick[$e->id] = [$rank, $e, $b];
        }
        $matched = count($pick); $changed = 0;
        foreach ($pick as [, $e, $b]) $changed += $this->apply($club->id, $e, self::reading($b));
        return ['matched' => $matched, 'changed' => $changed];
    }

    /** Normalised values; fields Boot did not report are left out. */
    public static function reading(array $b): array
    {
        $out = [];
        foreach (self::FIELDS as $col => $key) {
            $v = trim(preg_replace('/\s+/u', ' ', (string) ($b[$key] ?? '')));
            if ($v === '' || $v === '-' || $v === '0') continue;
            if ($col === 'ram_mb') { $mb = self::ramMb($v); if ($mb) $out[$col] = $mb; continue; }
            if ($col === 'nic') $v = self::nic($v);
            $out[$col] = mb_substr($v, 0, 255);
        }
        return $out;
    }

    /** "VEN_10EC&DEV_8125&SUBSYS_…\\0100…" → "Realtek 8125"; plain names pass through. */
    public static function nic(string $v): string
    {
        if (! preg_match('/VEN_([0-9A-F]{4})&DEV_([0-9A-F]{4})/i', $v, $m)) return $v;
        $vendor = ['10EC' => 'Realtek', '8086' => 'Intel', '14E4' => 'Broadcom', '1969' => 'Qualcomm Atheros', '1D6A' => 'Aquantia', '10B7' => '3Com'][strtoupper($m[1])] ?? 'VEN '.strtoupper($m[1]);
        return $vendor.' '.strtoupper($m[2]);
    }

    /** "16 GB", "16384MB", "16", "15.9G" → MB. */
    public static function ramMb(string $v): ?int
    {
        if (! preg_match('/(\d+(?:[.,]\d+)?)\s*([GMT])?/i', $v, $m)) return null;
        $n = (float) str_replace(',', '.', $m[1]);
        $unit = strtoupper($m[2] ?? ($n < 1024 ? 'G' : 'M'));
        $mb = (int) round($n * ['M' => 1, 'G' => 1024, 'T' => 1048576][$unit]);
        return $mb >= 512 && $mb <= 4194304 ? $mb : null;
    }

    private function apply(int $clubId, object $e, array $now): int
    {
        if (! $now) return 0;
        return DB::transaction(function () use ($clubId, $e, $now) {
            $current = DB::table('equipment')->where('id', $e->id)->lockForUpdate()->first();
            $diff = []; $update = ['hardware_checked_at' => now()];
            foreach ($now as $col => $value) {
                $old = $current->$col;
                if ($old === null || $old === '') { $update[$col] = $value; continue; }        // first inventory: fill quietly
                if (self::same($col, $old, $value)) continue;
                $update[$col] = $value; $diff[$col] = [$old, $value];
            }
            DB::table('equipment')->where('id', $e->id)->update($update);
            foreach (array_diff_key($update, ['hardware_checked_at' => 1]) as $col => $value) {
                DB::table('equipment_changes')->insert(['equipment_id' => $e->id, 'field' => $col, 'old_value' => $current->$col,
                    'new_value' => (string) $value, 'source' => 'boot', 'created_at' => now()]);
            }
            // The first Boot sync of a PC is its baseline: values typed by hand before are replaced quietly.
            if ($diff && $current->hardware_checked_at !== null) {
                $lines = array_map(fn ($col, $d) => self::LABELS[$col].': '.self::show($col, $d[0]).' → '.self::show($col, $d[1]), array_keys($diff), $diff);
                $this->events->event($clubId, 'hardware_changed', 'hardware:'.$e->id.':'.sha1(json_encode($diff)).':'.now()->format('Ymd'),
                    ['equipment_id' => $e->id, 'changes' => $diff, 'message' => "🧩 {$current->name}: изменилось железо\n".implode("\n", $lines)]);
            }
            return $diff && $current->hardware_checked_at !== null ? 1 : 0;
        });
    }

    private static function same(string $col, $a, $b): bool
    {
        if ($col === 'ram_mb') return abs((int) $a - (int) $b) <= max(256, (int) $a * 0.03);   // firmware reports drift a little
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $a))) === mb_strtolower((string) $b);
    }

    public static function show(string $col, $v): string
    {
        if ($col !== 'ram_mb') return (string) $v;
        $gb = (int) $v / 1024;
        return (fmod($gb, 1.0) === 0.0 ? (int) $gb : round($gb, 1)).' ГБ';
    }
}
