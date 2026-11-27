<?php

namespace App\Http\Controllers;

use App\Domain\Network\SwitchReport;
use App\Models\{Club, Equipment, SnmpCollector, SwitchSnmp};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\Rule;

/** Club switches polled over SNMP by a collector running inside the club network. */
class SnmpController extends Controller
{
    public const INTERVAL = 120;

    /* ---------- collector API (Bearer ccs_ token) ---------- */

    private function collector(Request $r): SnmpCollector
    {
        $c = SnmpCollector::findByToken($r->bearerToken());
        abort_unless($c, 401, 'Неверный токен сборщика.');
        $c->forceFill(['last_seen_at' => now(), 'last_ip' => $r->ip(),
            'hostname' => $r->header('X-Collector-Host') ? mb_substr((string) $r->header('X-Collector-Host'), 0, 120) : $c->hostname,
            'version' => $r->header('X-Collector-Version') ? mb_substr((string) $r->header('X-Collector-Version'), 0, 20) : $c->version])->save();
        return $c;
    }

    public function config(Request $r)
    {
        $c = $this->collector($r);
        $switches = SwitchSnmp::query()->join('equipment as e', 'e.id', '=', 'switch_snmp.equipment_id')
            ->where('e.club_id', $c->club_id)->where('e.status', '!=', 'retired')->get(['switch_snmp.*', 'e.name']);
        return response()->json(['interval' => self::INTERVAL, 'switches' => $switches->map(fn ($s) => [
            'id' => $s->equipment_id, 'name' => $s->name, 'host' => $s->host, 'port' => $s->port, 'version' => $s->version, 'community' => $s->community,
        ])->values()]);
    }

    public function report(Request $r)
    {
        $c = $this->collector($r);
        $r->validate(['switches' => 'present|array|max:64', 'switches.*.id' => 'required|integer', 'switches.*.ports' => 'array|max:600', 'switches.*.fdb' => 'array|max:20000']);
        return response()->json(['ok' => true] + app(SwitchReport::class)->ingest($c, $r->all()));
    }

    /* ---------- admin ---------- */

    public function store(Request $r, Club $club)
    {
        Gate::authorize('update', $club);
        $d = $r->validate(['name' => 'required|string|max:255', 'zone' => 'nullable|string|max:255', 'host' => 'required|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535', 'version' => ['nullable', Rule::in(['1', '2c'])], 'community' => 'required|string|max:120']);
        DB::transaction(function () use ($club, $d) {
            $e = Equipment::create(['club_id' => $club->id, 'name' => $d['name'], 'zone' => $d['zone'] ?? null, 'type' => 'switch', 'ip' => filter_var($d['host'], FILTER_VALIDATE_IP) ? $d['host'] : null]);
            SwitchSnmp::create(['equipment_id' => $e->id, 'host' => $d['host'], 'port' => $d['port'] ?? 161, 'version' => $d['version'] ?? '2c', 'community' => $d['community']]);
        });
        return back()->with('success', 'Коммутатор добавлен. Сборщик начнёт опрос в течение 2 минут.');
    }

    public function update(Request $r, Equipment $equipment)
    {
        Gate::authorize('update', $equipment->club);
        abort_unless($equipment->type === 'switch', 404);
        $d = $r->validate(['host' => 'required|string|max:255', 'port' => 'nullable|integer|min:1|max:65535', 'version' => ['nullable', Rule::in(['1', '2c'])], 'community' => 'nullable|string|max:120']);
        $s = SwitchSnmp::firstOrNew(['equipment_id' => $equipment->id]);
        if (! $s->exists && empty($d['community'])) return back()->withErrors(['community' => 'Укажите SNMP community.']);
        $s->host = $d['host']; $s->port = $d['port'] ?? 161; $s->version = $d['version'] ?? '2c';
        if (! empty($d['community'])) $s->community = $d['community'];
        $s->save();
        return back()->with('success', 'Настройки SNMP сохранены.');
    }

    public function issue(Request $r, Club $club)
    {
        Gate::authorize('update', $club);
        $d = $r->validate(['name' => 'nullable|string|max:120']);
        [, $token] = SnmpCollector::issue($club->id, $d['name'] ?? 'Сборщик SNMP');
        $url = rtrim(config('app.url'), '/').'/snmp/install.ps1';
        return back()->with('snmpInstall', "& ([scriptblock]::Create((irm '{$url}'))) -Token '{$token}'");
    }

    public function revoke(Club $club, SnmpCollector $collector)
    {
        Gate::authorize('update', $club);
        abort_unless($collector->club_id === $club->id, 404);
        $collector->forceFill(['revoked_at' => now()])->save();
        return back()->with('success', 'Сборщик отключён.');
    }

    /** Public: installer script (no secrets; the token is passed on the command line). */
    public function script()
    {
        $ps = str_replace('__API__', rtrim(config('app.url'), '/'), file_get_contents(resource_path('scripts/snmp-collector-install.ps1')));
        return response($ps, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /** Public: the collector binary (no secrets inside). */
    public function binary()
    {
        $path = resource_path('collector/cclub-snmp.exe');
        abort_unless(is_file($path), 404);
        return response()->download($path, 'cclub-snmp.exe', ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store']);
    }

    /** Data for the club page: switches, collectors. */
    public static function clubData(Club $club): array
    {
        $now = now();
        return [
            'switches' => DB::table('equipment as e')->leftJoin('switch_snmp as s', 's.equipment_id', '=', 'e.id')
                ->where('e.club_id', $club->id)->where('e.type', 'switch')->orderBy('e.name')
                ->get(['e.id', 'e.name', 'e.zone', 's.host', 's.polled_at', 's.last_error', 's.sys_descr'])
                ->map(function ($s) {
                    $ports = DB::table('switch_ports')->where('switch_id', $s->id)->get(['up', 'speed_mbps', 'uplink']);
                    $s->ports_up = $ports->where('up', true)->count();
                    $s->ports_total = $ports->count();
                    $s->slow = $ports->filter(fn ($p) => $p->up && ! $p->uplink && $p->speed_mbps !== null && $p->speed_mbps < 1000)->count();
                    $s->pcs = DB::table('equipment_ports')->where('switch_id', $s->id)->count();
                    return $s;
                })->values(),
            'collectors' => SnmpCollector::where('club_id', $club->id)->whereNull('revoked_at')->orderBy('id')
                ->get(['id', 'name', 'token_hint', 'hostname', 'version', 'last_seen_at'])
                ->map(fn ($c) => $c->toArray() + ['online' => $c->last_seen_at && $c->last_seen_at->gt($now->copy()->subSeconds(self::INTERVAL * 3))])->values(),
        ];
    }

    /** Data for a switch page: port map with the PC in each port. */
    public static function switchData(Equipment $sw): array
    {
        $pcs = DB::table('equipment_ports as ep')->join('equipment as e', 'e.id', '=', 'ep.equipment_id')->where('ep.switch_id', $sw->id)
            ->get(['ep.if_index', 'e.id', 'e.name', 'ep.seen_at'])->groupBy('if_index');
        $snmp = SwitchSnmp::find($sw->id);
        return [
            'snmp' => $snmp ? ['host' => $snmp->host, 'port' => $snmp->port, 'version' => $snmp->version, 'polled_at' => $snmp->polled_at, 'last_error' => $snmp->last_error, 'sys_descr' => $snmp->sys_descr, 'model' => $snmp->model, 'firmware' => $snmp->firmware, 'serial' => $sw->serial_number] : null,
            'ports' => DB::table('switch_ports')->where('switch_id', $sw->id)->orderBy('port_no')->orderBy('if_index')->get()
                ->map(fn ($p) => ['if_index' => $p->if_index, 'port' => $p->port_no ?? $p->if_index, 'name' => $p->name, 'alias' => $p->alias, 'up' => (bool) $p->up,
                    'speed_mbps' => $p->speed_mbps, 'uplink' => (bool) $p->uplink, 'macs' => $p->mac_count, 'changed_at' => $p->changed_at,
                    'pcs' => ($pcs[$p->if_index] ?? collect())->map(fn ($x) => ['id' => $x->id, 'name' => $x->name])->values()])->values(),
        ];
    }
}
