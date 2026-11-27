<?php

namespace App\Domain\Network;

use App\Models\SnmpCollector;
use Illuminate\Support\Facades\DB;

/**
 * Takes one poll from the club's SNMP collector: port table (IF-MIB) and learned MACs (BRIDGE-MIB)
 * of every switch, and works out which switch port each PC is plugged into.
 */
final class SwitchReport
{
    /** A port that learned more MACs than this leads to another switch/router, not to one PC. */
    public const UPLINK_MACS = 3;

    /** @return array{switches: int, ports: int, pcs: int} */
    public function ingest(SnmpCollector $collector, array $payload): array
    {
        $now = now();
        $mine = DB::table('switch_snmp as s')->join('equipment as e', 'e.id', '=', 's.equipment_id')
            ->where('e.club_id', $collector->club_id)->pluck('s.equipment_id')->flip();
        $pcsByMac = [];
        foreach (DB::table('equipment')->where('club_id', $collector->club_id)->whereIn('type', ['pc', 'server'])->whereNotNull('mac')->get(['id', 'mac']) as $e) {
            $pcsByMac[self::mac($e->mac)] = $e->id;
        }
        $stats = ['switches' => 0, 'ports' => 0, 'pcs' => 0];
        foreach ((array) ($payload['switches'] ?? []) as $sw) {
            $id = (int) ($sw['id'] ?? 0);
            if (! isset($mine[$id])) continue;
            DB::transaction(function () use ($id, $sw, $now, $pcsByMac, &$stats) {
                if (empty($sw['ok'])) {
                    DB::table('switch_snmp')->where('equipment_id', $id)->update(['last_error' => mb_substr((string) ($sw['error'] ?? 'Нет ответа по SNMP'), 0, 500), 'updated_at' => $now]);
                    return;
                }
                $macs = [];
                foreach ((array) ($sw['fdb'] ?? []) as $f) {
                    $mac = self::mac((string) ($f['mac'] ?? ''));
                    $if = (int) ($f['if_index'] ?? 0);
                    if (strlen($mac) === 12 && $if > 0) $macs[$if][$mac] = true;
                }
                $old = DB::table('switch_ports')->where('switch_id', $id)->get()->keyBy('if_index');
                $uplinks = [];
                foreach ((array) ($sw['ports'] ?? []) as $p) {
                    $if = (int) ($p['if_index'] ?? 0);
                    if ($if <= 0) continue;
                    $name = mb_substr(trim((string) ($p['name'] ?? '')) ?: 'if'.$if, 0, 80);
                    $up = (int) ($p['oper'] ?? 2) === 1;
                    $speed = isset($p['speed_mbps']) && $up ? (int) $p['speed_mbps'] : null;
                    $count = count($macs[$if] ?? []);
                    $uplink = $count > self::UPLINK_MACS;
                    if ($uplink) $uplinks[$if] = true;
                    $prev = $old[$if] ?? null;
                    $row = ['port_no' => self::portNo($name, $if), 'name' => $name, 'alias' => ($a = trim((string) ($p['alias'] ?? ''))) !== '' ? mb_substr($a, 0, 255) : null,
                        'up' => $up, 'speed_mbps' => $speed, 'mac_count' => $count, 'uplink' => $uplink, 'updated_at' => $now];
                    if (! $prev || (bool) $prev->up !== $up || $prev->speed_mbps != $speed) $row['changed_at'] = $now;
                    DB::table('switch_ports')->updateOrInsert(['switch_id' => $id, 'if_index' => $if], $row);
                    $stats['ports']++;
                }
                foreach ($macs as $if => $set) {
                    if (isset($uplinks[$if])) continue;
                    foreach (array_keys($set) as $mac) {
                        if (! isset($pcsByMac[$mac])) continue;
                        DB::table('equipment_ports')->upsert([['equipment_id' => $pcsByMac[$mac], 'switch_id' => $id, 'if_index' => $if, 'seen_at' => $now]],
                            ['equipment_id'], ['switch_id', 'if_index', 'seen_at']);
                        $stats['pcs']++;
                    }
                }
                DB::table('switch_snmp')->where('equipment_id', $id)->update([
                    'sys_descr' => isset($sw['sys_descr']) ? mb_substr((string) $sw['sys_descr'], 0, 500) : null,
                    'uptime_seconds' => isset($sw['uptime_seconds']) ? (int) $sw['uptime_seconds'] : null,
                    'model' => ($m = trim((string) ($sw['model'] ?? ''))) !== '' ? mb_substr($m, 0, 120) : null,
                    'firmware' => ($f = trim((string) ($sw['firmware'] ?? ''))) !== '' ? mb_substr($f, 0, 120) : null,
                    'polled_at' => $now, 'last_error' => null, 'updated_at' => $now]);
                // Passport fields the switch reports about itself (ENTITY-MIB / BRIDGE-MIB): kept in sync automatically.
                $eq = ['last_seen_at' => $now];
                if (($serial = trim((string) ($sw['serial'] ?? ''))) !== '') $eq['serial_number'] = mb_substr($serial, 0, 255);
                if (strlen($base = self::mac((string) ($sw['base_mac'] ?? ''))) === 12 && $base !== '000000000000') $eq['mac'] = implode(':', str_split($base, 2));
                DB::table('equipment')->where('id', $id)->update($eq);
                $stats['switches']++;
            });
        }
        return $stats;
    }

    /**
     * What a port at below 1 Gbit/s means. A switched-off PC with Wake-on-LAN keeps its link up at
     * 100 Mbit/s, so a slow port is a cable problem only while the PC on it is actually on.
     * @return 'down'|'uplink'|'ok'|'slow'|'standby'|'slow_unknown'
     */
    public static function state(bool $up, bool $uplink, ?int $speed, ?bool $pcOnline): string
    {
        if (! $up) return 'down';
        if ($uplink) return 'uplink';
        if ($speed === null || $speed >= 1000) return 'ok';
        return match ($pcOnline) { true => 'slow', false => 'standby', null => 'slow_unknown' };
    }

    /** Online flag for a PC name given iCafeCloud's online list (null list = unknown). */
    public static function online(?array $onlineNames, ?string $pcName): ?bool
    {
        if ($onlineNames === null || $pcName === null) return null;
        return isset($onlineNames[mb_strtolower(trim($pcName))]);
    }

    /** iCafeCloud online list of the club that owns this equipment (cached 30 s), null if unknown. */
    public static function onlineFor(int $clubId): ?array
    {
        static $memo = [];
        if (array_key_exists($clubId, $memo)) return $memo[$clubId];
        $club = \App\Models\Club::find($clubId);
        try { return $memo[$clubId] = $club ? app(\App\Domain\Monitoring\LiveComputers::class)->onlineNames($club) : null; }
        catch (\Throwable) { return $memo[$clubId] = null; }
    }

    public static function mac(string $mac): string
    {
        return strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac));
    }

    /** "GigabitEthernet1/0/21", "ge21", "Port 21" → 21. */
    public static function portNo(string $name, int $ifIndex): int
    {
        return preg_match('/(\d+)\s*$/', $name, $m) ? (int) $m[1] : $ifIndex;
    }

    /**
     * Where a PC's cable goes: switch, port, current state of that port.
     * @return array{switch_id:int, switch:string, zone:?string, port:int, port_name:string, up:bool, speed_mbps:?int, seen_at:string, polled_at:?string}|null
     */
    public static function forEquipment(int $equipmentId): ?array
    {
        $r = DB::table('equipment_ports as ep')->join('equipment as sw', 'sw.id', '=', 'ep.switch_id')
            ->leftJoin('switch_ports as p', fn ($j) => $j->on('p.switch_id', '=', 'ep.switch_id')->on('p.if_index', '=', 'ep.if_index'))
            ->leftJoin('switch_snmp as s', 's.equipment_id', '=', 'ep.switch_id')
            ->where('ep.equipment_id', $equipmentId)
            ->first(['ep.switch_id', 'sw.name as switch', 'sw.zone', 'sw.club_id', 'ep.if_index', 'p.port_no', 'p.name as port_name', 'p.up', 'p.uplink', 'p.speed_mbps', 'ep.seen_at', 's.polled_at']);
        if (! $r) return null;
        $speed = $r->speed_mbps !== null ? (int) $r->speed_mbps : null;
        $online = ($r->up && $speed !== null && $speed < 1000)
            ? self::online(self::onlineFor((int) $r->club_id), DB::table('equipment')->where('id', $equipmentId)->value('name')) : null;
        return ['switch_id' => (int) $r->switch_id, 'switch' => $r->switch, 'zone' => $r->zone, 'port' => (int) ($r->port_no ?? $r->if_index),
            'port_name' => $r->port_name ?? ('if'.$r->if_index), 'up' => (bool) $r->up, 'speed_mbps' => $r->speed_mbps !== null ? (int) $r->speed_mbps : null,
            'seen_at' => (string) $r->seen_at, 'polled_at' => $r->polled_at, 'pc_online' => $online,
            'state' => self::state((bool) $r->up, (bool) $r->uplink, $speed, $online)];
    }
}
