<?php

namespace Tests\Feature;

use App\Domain\Monitoring\{ClubTemplate, IcafeBootTelemetry, LiveComputers};
use App\Models\{Club, Equipment};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

class IcafeBootTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private function club(): Club
    {
        $c = Club::create(['name' => 'TeamPro', 'address' => 'Test']);
        $c->icafe_license = 123; $c->icafe_token = 'private-token'; $c->save();
        app(ClubTemplate::class)->save($c, 50, 79, 0);
        return $c->fresh();
    }

    private function pc(Club $c, string $name, ?string $mac = null): Equipment
    {
        $e = Equipment::create(['club_id' => $c->id, 'name' => $name, 'type' => 'pc', 'mac' => $mac]);
        $e->icafe_pc_name = $name; $e->save();
        return $e;
    }

    private function fake(array $pcs, int $result = 1): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/pcSessions/sendWssCommand' => Http::response(['code' => 200, 'data' => ['result' => $result, 'message' => ['action' => 'pcs_status', 'data' => ['pcs' => $pcs]]]]),
            '*/bootPcs' => Http::response(['code' => 200, 'data' => [
                ['bpc_id' => 10, 'bpc_icafe_id' => 123, 'bpc_name' => 'PC017', 'bpc_mac' => '04:D9:F5:7F:F5:C5', 'bpc_password' => ''],
                ['bpc_id' => 11, 'bpc_icafe_id' => 123, 'bpc_name' => 'Renamed', 'bpc_mac' => '04:D9:F5:00:00:11'],
                ['bpc_id' => 12, 'bpc_icafe_id' => 123, 'bpc_name' => 'PC099', 'bpc_mac' => ''],
            ]]),
            '*/onlinePcList' => Http::response(['code' => 200, 'data' => [['pc_name' => 'PC017', 'is_connected' => 1], ['pc_name' => 'PC018', 'is_connected' => 1]]]),
        ]);
    }

    public function test_boot_readings_become_samples_matched_by_name_or_mac_and_raise_alerts(): void
    {
        $c = $this->club();
        $a = $this->pc($c, 'PC017');
        $b = $this->pc($c, 'PC018', '04:d9:f5:00:00:11');
        $this->fake([
            ['id' => 10, 'cpu_temp' => '62', 'gpu_temp' => '52', 'cpu_usage' => '39%%', 'ram_usage' => '56%%', 'activeprocess' => 'dota2.exe'],
            ['id' => 11, 'cpu_temp' => '85', 'gpu_temp' => '0', 'cpu_usage' => '0%%', 'ram_usage' => '40%%'],
            ['id' => 12, 'cpu_temp' => '40', 'gpu_temp' => '40'],
        ]);

        $r = app(IcafeBootTelemetry::class)->collect($c);
        $this->assertSame(['matched' => 2, 'unmatched' => 1, 'supported' => true], $r);

        $sa = DB::table('telemetry_samples')->where('equipment_id', $a->id)->first();
        $this->assertEquals(62, $sa->cpu_temp); $this->assertEquals(52, $sa->gpu_temp);
        $this->assertEquals(39, $sa->cpu_load); $this->assertEquals(56, $sa->ram_used_percent);
        $sb = DB::table('telemetry_samples')->where('equipment_id', $b->id)->first();
        $this->assertNull($sb->gpu_temp); $this->assertEquals(0, $sb->cpu_load);
        $this->assertSame('unavailable', json_decode($sb->sensor_status, true)['gpu_temp']);

        $this->assertSame('active', DB::table('alerts')->where('equipment_id', $b->id)->where('metric', 'cpu_temp')->value('state'));
        $this->assertSame('normal', DB::table('alerts')->where('equipment_id', $a->id)->where('metric', 'cpu_temp')->value('state'));

        $live = collect(app(LiveComputers::class)->snapshot($c)['pcs'])->keyBy('name');
        $this->assertSame(62.0, $live['PC017']['cpu_temp']);
        $this->assertSame('critical', $live['PC018']['severity']);
    }

    public function test_club_without_boot_backs_off(): void
    {
        $c = $this->club();
        $this->pc($c, 'PC017');
        $this->fake([], 0);
        $this->assertFalse(app(IcafeBootTelemetry::class)->collect($c)['supported']);
        $this->assertFalse(app(IcafeBootTelemetry::class)->collect($c)['supported']);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('telemetry_samples', 0);
    }

    public function test_sample_parsing(): void
    {
        $s = IcafeBootTelemetry::sample(['cpu_temp' => '200', 'gpu_temp' => 'n/a', 'cpu_usage' => '100%%', 'ram_usage' => '']);
        $this->assertNull($s['cpu_temp']); $this->assertNull($s['gpu_temp']);
        $this->assertSame(100.0, $s['cpu_load']); $this->assertNull($s['ram_used_percent']);
        $this->assertSame('unsupported', $s['sensor_status']['gpu_load']);
    }

    public function test_link_drop_from_gigabit_triggers_after_two_polls_and_recovers(): void
    {
        $c = $this->club();
        $a = $this->pc($c, 'PC017');
        $speed = '1000M';
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$speed) {
            if (str_ends_with($request->url(), '/bootPcs')) return Http::response(['code' => 200, 'data' => [['bpc_id' => 10, 'bpc_icafe_id' => 123, 'bpc_name' => 'PC017', 'bpc_mac' => '']]]);
            if (str_ends_with($request->url(), '/onlinePcList')) return Http::response(['code' => 200, 'data' => [['pc_name' => 'PC017', 'is_connected' => 1]]]);
            return Http::response(['code' => 200, 'data' => ['result' => 1, 'message' => ['data' => ['pcs' => [['id' => 10, 'cpu_temp' => '50', 'gpu_temp' => '50', 'linkspeed' => $speed]]]]]]);
        });
        $boot = app(IcafeBootTelemetry::class);
        $state = fn () => DB::table('alerts')->where('equipment_id', $a->id)->where('metric', 'link_speed')->value('state');

        $boot->collect($c);
        $this->assertSame(1000, (int) $a->fresh()->link_expected_mbps);
        $this->assertSame('normal', $state());

        $speed = '100M';
        $this->travel(60)->seconds(); $boot->collect($c);
        $this->assertSame('normal', $state(), 'one degraded poll is not enough');
        $live = collect(app(LiveComputers::class)->snapshot($c)['pcs'])->first();
        $this->assertSame(100, $live['link_mbps']); $this->assertSame(1000, $live['link_expected_mbps']);

        $this->travel(60)->seconds(); $boot->collect($c);
        $this->assertSame('active', $state());
        $event = DB::table('outbox_events')->where('kind', 'monitor_problem')->first();
        $this->assertStringContainsString('PC017: сетевой линк упал до 100', json_decode($event->payload, true)['message']);
        $this->assertTrue(collect(app(LiveComputers::class)->snapshot($c)['pcs'])->first()['link_alert']);
        // Club staff get a separate, plain request to reseat the cable.
        $down = DB::table('outbox_events')->where('kind', 'link_down')->sole();
        $text = \App\Jobs\DeliverTelegram::text('link_down', $down->id, $down->payload);
        $this->assertStringStartsWith('⚠️ PC017', $text);
        $this->assertStringContainsString('100 Мбит/с вместо 1 Гбит/с', $text);
        $this->assertStringContainsString('подключите заново', $text);
        $this->assertStringNotContainsString('equipment_id', $text, 'no technical payload for club staff');
        $this->assertStringContainsString('"equipment_id"', \App\Jobs\DeliverTelegram::text('monitor_problem', 1, $down->payload), 'internal kinds keep the details');

        $speed = '1000M';
        $this->travel(60)->seconds(); $boot->collect($c);
        $this->assertSame('normal', $state());
        $this->assertDatabaseHas('outbox_events', ['kind' => 'monitor_recovered']);
        $up = DB::table('outbox_events')->where('kind', 'link_restored')->sole();
        $this->assertStringContainsString('Сеть восстановлена: 1 Гбит/с', \App\Jobs\DeliverTelegram::text('link_restored', $up->id, $up->payload));
    }

    public function test_pc_that_starts_at_100_is_flagged_and_link_parsing(): void
    {
        $this->assertSame(1000, IcafeBootTelemetry::linkMbps('1000M'));
        $this->assertSame(100, IcafeBootTelemetry::linkMbps('100M'));
        $this->assertSame(2500, IcafeBootTelemetry::linkMbps('2.5G'));
        $this->assertNull(IcafeBootTelemetry::linkMbps(''));
        $this->assertNull(IcafeBootTelemetry::linkMbps('unknown'));

        $c = $this->club();
        $a = $this->pc($c, 'PC017');
        $this->fake([['id' => 10, 'cpu_temp' => '50', 'gpu_temp' => '50', 'linkspeed' => '100M']]);
        $boot = app(IcafeBootTelemetry::class);
        $boot->collect($c); $this->travel(60)->seconds(); $boot->collect($c);
        $this->assertSame('active', DB::table('alerts')->where('equipment_id', $a->id)->where('metric', 'link_speed')->value('state'));
    }
}
