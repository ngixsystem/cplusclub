<?php

namespace Tests\Feature;

use App\Domain\Network\SwitchReport;
use App\Models\{Club, Equipment, SnmpCollector, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SnmpSwitchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_adds_switch_collector_pulls_config_and_reports_ports(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $club = Club::create(['name' => 'SPOT', 'address' => 'x']);
        $other = Club::create(['name' => 'TeamPro', 'address' => 'y']);
        $this->actingAs($owner)->post('/clubs/'.$club->id.'/switches', ['name' => 'Свитч 3', 'zone' => 'Зал A', 'host' => '192.168.1.13', 'community' => 'secret-ro'])->assertRedirect();
        $sw = Equipment::where('type', 'switch')->firstOrFail();
        $this->assertNotSame('secret-ro', DB::table('switch_snmp')->value('community'), 'community is stored encrypted');

        // Collector token is shown once, inside the install command.
        $this->post('/clubs/'.$club->id.'/snmp-collectors')->assertSessionHas('snmpInstall');
        preg_match("/-Token '([^']+)'/", session('snmpInstall'), $m);
        $token = $m[1];
        [, $foreign] = SnmpCollector::issue($other->id, 'other');

        $this->getJson('/api/v1/snmp/config')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/v1/snmp/config')->assertOk()
            ->assertJsonPath('switches.0.host', '192.168.1.13')->assertJsonPath('switches.0.community', 'secret-ro')
            ->assertJsonPath('update.version', trim(file_get_contents(resource_path('collector/VERSION'))))
            ->assertJsonPath('update.sha256', hash_file('sha256', resource_path('collector/cclub-snmp.exe')));
        $this->withToken($foreign)->getJson('/api/v1/snmp/config')->assertOk()->assertJsonCount(0, 'switches');

        $pc = Equipment::create(['club_id' => $club->id, 'name' => 'PC37', 'type' => 'pc', 'mac' => 'AA:BB:CC:00:00:37']);
        $pc2 = Equipment::create(['club_id' => $club->id, 'name' => 'PC38', 'type' => 'pc', 'mac' => 'aa-bb-cc-00-00-38']);
        $uplinkMacs = array_map(fn ($i) => ['mac' => sprintf('11:22:33:44:55:%02x', $i), 'if_index' => 50], range(1, 10));
        $report = ['switches' => [[
            'id' => $sw->id, 'ok' => true, 'sys_descr' => 'PXT-S2790G-48TX', 'serial' => 'PXT48A1234567', 'model' => 'PXT-S2790G-48TX', 'firmware' => 'V2.1.0', 'base_mac' => 'a0b1c2d3e4f5',
            'ports' => [
                ['if_index' => 21, 'name' => 'GigabitEthernet0/0/21', 'oper' => 1, 'speed_mbps' => 100],
                ['if_index' => 22, 'name' => 'GigabitEthernet0/0/22', 'oper' => 1, 'speed_mbps' => 1000],
                ['if_index' => 23, 'name' => 'GigabitEthernet0/0/23', 'oper' => 2, 'speed_mbps' => 0],
                ['if_index' => 50, 'name' => 'TenGigabitEthernet0/0/2', 'oper' => 1, 'speed_mbps' => 10000],
            ],
            // PC38's MAC is also learned on the uplink: the access port wins.
            'fdb' => array_merge([['mac' => 'aabbcc000037', 'if_index' => 21], ['mac' => 'aabbcc000038', 'if_index' => 22], ['mac' => 'aabbcc000038', 'if_index' => 50]], $uplinkMacs),
        ]]];
        $this->withToken($foreign)->postJson('/api/v1/snmp/report', $report)->assertOk()->assertJsonPath('switches', 0);
        $this->withToken($token)->postJson('/api/v1/snmp/report', $report)->assertOk()->assertJsonPath('switches', 1)->assertJsonPath('pcs', 2);

        $cable = SwitchReport::forEquipment($pc->id);
        $this->assertSame('Свитч 3', $cable['switch']);
        $this->assertSame(21, $cable['port']);
        $this->assertSame(100, $cable['speed_mbps']);
        $this->assertSame(22, SwitchReport::forEquipment($pc2->id)['port']);
        $this->assertTrue((bool) DB::table('switch_ports')->where('if_index', 50)->value('uplink'));
        $this->assertNull(DB::table('switch_ports')->where('if_index', 23)->value('speed_mbps'));
        $this->assertSame('PXT48A1234567', $sw->fresh()->serial_number);
        $this->assertSame('a0:b1:c2:d3:e4:f5', $sw->fresh()->mac);
        $this->assertSame('V2.1.0', DB::table('switch_snmp')->value('firmware'));

        // PC switched off: its MAC ages out of the switch, the last known port is kept.
        $report['switches'][0]['fdb'] = $uplinkMacs;
        $this->withToken($token)->postJson('/api/v1/snmp/report', $report)->assertOk();
        $this->assertSame(21, SwitchReport::forEquipment($pc->id)['port']);

        // A failed poll is shown on the switch, ports stay.
        $this->withToken($token)->postJson('/api/v1/snmp/report', ['switches' => [['id' => $sw->id, 'ok' => false, 'error' => 'timeout']]])->assertOk();
        $this->assertSame('timeout', DB::table('switch_snmp')->value('last_error'));
    }

    public function test_link_ticket_shows_the_switch_port(): void
    {
        $this->withoutVite();
        $u = User::factory()->create(['role' => 'specialist']); $club = Club::create(['name' => 'SPOT', 'address' => 'x']); $u->clubs()->attach($club);
        $sw = Equipment::create(['club_id' => $club->id, 'name' => 'Свитч 2', 'type' => 'switch']);
        $pc = Equipment::create(['club_id' => $club->id, 'name' => 'PC05', 'type' => 'pc']);
        DB::table('switch_ports')->insert(['switch_id' => $sw->id, 'if_index' => 5, 'port_no' => 5, 'name' => 'ge5', 'up' => true, 'speed_mbps' => 100]);
        DB::table('equipment_ports')->insert(['equipment_id' => $pc->id, 'switch_id' => $sw->id, 'if_index' => 5, 'seen_at' => now()]);
        $this->assertSame(['switch' => 'Свитч 2', 'port' => 5, 'speed_mbps' => 100], array_intersect_key(SwitchReport::forEquipment($pc->id), array_flip(['switch', 'port', 'speed_mbps'])));
        $this->assertSame(21, SwitchReport::portNo('GigabitEthernet1/0/21', 3));
        $this->assertSame(7, SwitchReport::portNo('', 7));
        // Non-owners cannot add switches.
        $this->actingAs($u)->post('/clubs/'.$club->id.'/switches', ['name' => 'x', 'host' => '10.0.0.1', 'community' => 'c'])->assertForbidden();
    }

    public function test_slow_port_is_a_problem_only_while_the_pc_is_on(): void
    {
        $this->assertSame('slow', SwitchReport::state(true, false, 100, true));
        $this->assertSame('standby', SwitchReport::state(true, false, 100, false));
        $this->assertSame('slow_unknown', SwitchReport::state(true, false, 100, null));
        $this->assertSame('ok', SwitchReport::state(true, false, 1000, false));
        $this->assertSame('uplink', SwitchReport::state(true, true, 100, null));
        $this->assertSame('down', SwitchReport::state(false, false, null, true));

        $club = Club::create(['name' => 'SPOT', 'address' => 'x']);
        $club->forceFill(['icafe_license' => '12345', 'icafe_token' => 'tok'])->save();
        $club = $club->fresh();
        // iCafeCloud says PC05 is on, PC06 is off.
        \Illuminate\Support\Facades\Cache::put('icafe:online:'.$club->id.':'.hash('sha256', $club->icafe_token.'|'.$club->icafe_license),
            ['stale' => false, 'updated_at' => now()->toIso8601String(), 'online' => ['pc05'], 'error' => null, 'checked_at' => time()], 600);
        $sw = Equipment::create(['club_id' => $club->id, 'name' => 'SW1', 'type' => 'switch']);
        foreach ([5, 6] as $n) {
            $pc = Equipment::create(['club_id' => $club->id, 'name' => sprintf('PC%02d', $n), 'type' => 'pc']);
            DB::table('switch_ports')->insert(['switch_id' => $sw->id, 'if_index' => $n, 'port_no' => $n, 'name' => 'ge'.$n, 'up' => true, 'speed_mbps' => 100]);
            DB::table('equipment_ports')->insert(['equipment_id' => $pc->id, 'switch_id' => $sw->id, 'if_index' => $n, 'seen_at' => now()]);
        }
        $states = \App\Http\Controllers\SnmpController::portStates($sw->id, $club->id);
        $this->assertSame('slow', $states[5]['state']);
        $this->assertSame('standby', $states[6]['state']);
        $this->assertSame('slow', SwitchReport::forEquipment(Equipment::where('name', 'PC05')->value('id'))['state']);
        $this->assertSame('standby', SwitchReport::forEquipment(Equipment::where('name', 'PC06')->value('id'))['state']);
        $this->assertSame(1, \App\Http\Controllers\SnmpController::clubData($club)['switches'][0]->slow);
    }
}
