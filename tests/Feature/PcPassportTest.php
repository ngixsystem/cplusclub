<?php

namespace Tests\Feature;

use App\Domain\Monitoring\{BootHardware, EquipmentPassport};
use App\Models\{Club, Equipment, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

class PcPassportTest extends TestCase
{
    use RefreshDatabase;

    private array $row = [];

    private function boot(array $row): void
    {
        $this->row = $row;
        if ($this->faked ?? false) return;
        $this->faked = true;
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['code' => 200, 'data' => [['bpc_id' => 10, 'bpc_icafe_id' => 123, 'bpc_name' => 'PC017', 'bpc_mac' => ''] + $this->row]]));
    }

    private bool $faked = false;

    public function test_first_reading_fills_the_passport_and_a_swap_raises_an_alert(): void
    {
        $club = Club::create(['name' => 'SPOT', 'address' => 'x']);
        $club->icafe_license = 123; $club->icafe_token = 'secret'; $club->save();
        $pc = Equipment::create(['club_id' => $club->id, 'name' => 'PC017', 'type' => 'pc']);

        $this->boot(['bpc_cpu' => 'Intel Core i5-12400F', 'bpc_vga' => 'NVIDIA GeForce RTX 3060', 'bpc_ram' => '16 GB', 'bpc_mb' => 'MSI B660', 'bpc_nic_description' => 'Realtek 2.5GbE']);
        $this->assertSame(['matched' => 1, 'changed' => 0], app(BootHardware::class)->sync($club->fresh()));
        $pc->refresh();
        $this->assertSame(['Intel Core i5-12400F', 'NVIDIA GeForce RTX 3060', 16384, 'MSI B660'], [$pc->cpu, $pc->gpu, $pc->ram_mb, $pc->motherboard]);
        $this->assertSame(0, DB::table('outbox_events')->where('kind', 'hardware_changed')->count());

        // Same hardware with cosmetic differences and RAM rounding: nothing to report.
        $this->boot(['bpc_cpu' => 'intel core  i5-12400F', 'bpc_vga' => 'NVIDIA GeForce RTX 3060', 'bpc_ram' => '16200MB']);
        $this->assertSame(0, app(BootHardware::class)->sync($club->fresh())['changed']);

        $this->boot(['bpc_cpu' => 'Intel Core i5-12400F', 'bpc_vga' => 'NVIDIA GeForce GTX 1660', 'bpc_ram' => '8 GB']);
        $this->assertSame(1, app(BootHardware::class)->sync($club->fresh())['changed']);
        $event = DB::table('outbox_events')->where('kind', 'hardware_changed')->sole();
        $message = json_decode($event->payload, true)['message'];
        $this->assertStringContainsString('Видеокарта: NVIDIA GeForce RTX 3060 → NVIDIA GeForce GTX 1660', $message);
        $this->assertStringContainsString('Оперативная память: 16 ГБ → 8 ГБ', $message);

        $passport = app(EquipmentPassport::class)->build($pc->fresh()->load('club'));
        $titles = collect($passport['timeline'])->pluck('title');
        $this->assertTrue($titles->contains('Видеокарта: изменение'));
        $this->assertTrue($titles->contains('Изменилось железо'));
        $this->assertSame('8 ГБ', $passport['hardware']['ram']);
    }

    public function test_duplicate_boot_entries_pick_one_and_first_sync_is_quiet(): void
    {
        $club = Club::create(['name' => 'SPOT', 'address' => 'x']);
        $club->icafe_license = 123; $club->icafe_token = 'secret'; $club->save();
        $pc = Equipment::create(['club_id' => $club->id, 'name' => '086', 'type' => 'pc', 'mac' => '00:11:22:33:44:55', 'cpu' => 'typed by hand']);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['code' => 200, 'data' => [
            ['bpc_id' => 1, 'bpc_icafe_id' => 123, 'bpc_name' => 'old-086', 'bpc_mac' => '001122334455', 'bpc_cpu' => 'Intel i3-12100F', 'bpc_last_time' => '2026-01-01 10:00:00'],
            ['bpc_id' => 2, 'bpc_icafe_id' => 123, 'bpc_name' => '086', 'bpc_mac' => '001122334455', 'bpc_cpu' => 'AMD Ryzen 7 7800X3D', 'bpc_last_time' => '2026-10-27 10:00:00',
                'bpc_nic_description' => 'VEN_10EC&DEV_8125&SUBSYS_88E11043&REV_0C\\01000000684CE00000'],
        ]]));
        foreach ([1, 2] as $run) $this->assertSame(['matched' => 1, 'changed' => 0], app(BootHardware::class)->sync($club->fresh()));
        $this->assertSame(['AMD Ryzen 7 7800X3D', 'Realtek 8125'], [$pc->fresh()->cpu, $pc->fresh()->nic]);
        $this->assertSame(0, DB::table('outbox_events')->where('kind', 'hardware_changed')->count());
    }

    public function test_ram_parsing(): void
    {
        $this->assertSame(16384, BootHardware::ramMb('16 GB'));
        $this->assertSame(16384, BootHardware::ramMb('16'));
        $this->assertSame(8192, BootHardware::ramMb('8192MB'));
        $this->assertSame(16282, BootHardware::ramMb('15.9G'));
        $this->assertNull(BootHardware::ramMb('n/a'));
    }

    public function test_passport_page_shows_timeline_and_admin_edits_are_logged(): void
    {
        $this->withoutVite();
        $club = Club::create(['name' => 'SPOT', 'address' => 'x']);
        $pc = Equipment::create(['club_id' => $club->id, 'name' => 'PC017', 'type' => 'pc', 'gpu' => 'RTX 3060']);
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);
        $pc->gpu = 'RTX 4060'; $pc->save();
        $c = DB::table('equipment_changes')->sole();
        $this->assertSame(['RTX 3060', 'RTX 4060', 'admin', $owner->id], [$c->old_value, $c->new_value, $c->source, $c->user_id]);
        DB::table('telemetry_hourly')->insert([['equipment_id' => $pc->id, 'hour' => now()->subDay()->startOfHour(), 'cpu_temp_avg' => 60, 'gpu_temp_avg' => 70, 'link_min' => 100, 'samples' => 60],
            ['equipment_id' => $pc->id, 'hour' => now()->startOfHour(), 'cpu_temp_avg' => 55, 'gpu_temp_avg' => 65, 'link_min' => 1000, 'samples' => 60]]);
        $this->get('/equipment/'.$pc->id)->assertOk()
            ->assertInertia(fn ($p) => $p->component('Equipment', false)->where('passport.charts.has_link', true)->has('passport.charts.labels', 2)->has('passport.timeline', 1));
    }
}
