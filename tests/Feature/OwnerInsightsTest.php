<?php

namespace Tests\Feature;

use App\Domain\Icafe\{CashControl, Occupancy, ShiftSync};
use App\Domain\Reports\DailyDigest;
use App\Models\{Club, TelegramChannel, User};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

class OwnerInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private float $refund = 0;
    private ?array $list = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->club = Club::create(['name' => 'SPOT', 'address' => 'x']);
        $this->club->icafe_license = random_int(1000, 999999); $this->club->icafe_token = 't'; $this->club->timezone = 'Asia/Tashkent'; $this->club->save();
        Http::fake(function ($req) {
            if (str_contains($req->url(), 'shiftDetail')) return Http::response(['code' => 200, 'data' => ['cash_refund' => $this->refund]]);
            if (str_contains($req->url(), 'shiftList')) return Http::response(['code' => 200, 'data' => $this->list ?? [
                ['shift_id' => 900, 'shift_staff_name' => 'Азиз', 'shift_start_time' => '2026-11-10 08:00:00', 'shift_end_time' => '-',
                    'total_amount' => '120000', 'cash' => '100000', 'credit_card' => '20000', 'qr' => '0'],
            ]]);
            return Http::response(['code' => 200, 'data' => []]);
        });
    }

    private function shift(string $id, string $localStart, ?string $localEnd, float $total, string $op = 'Диля'): object
    {
        $tz = 'Asia/Tashkent';
        $row = ['club_id' => $this->club->id, 'shift_id' => $id, 'operator' => $op, 'started_at' => CarbonImmutable::parse($localStart, $tz)->utc(),
            'ended_at' => $localEnd ? CarbonImmutable::parse($localEnd, $tz)->utc() : null, 'total' => $total, 'cash' => $total, 'card' => 0, 'qr' => 0, 'synced_at' => now()];
        $row['id'] = DB::table('icafe_shifts')->insertGetId($row);
        return (object) $row;
    }

    public function test_shift_sync_stores_shifts_in_utc(): void
    {
        $this->assertSame(1, app(ShiftSync::class)->sync($this->club));
        $s = DB::table('icafe_shifts')->first();
        $this->assertSame('900', $s->shift_id);
        $this->assertNull($s->ended_at);
        $this->assertSame('2026-11-10 03:00:00', CarbonImmutable::parse($s->started_at)->utc()->format('Y-m-d H:i:s'));
        $this->assertEquals(120000, (float) $s->total);
    }

    public function test_sync_skips_corrupted_amounts_and_drops_replaced_open_shift(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-12 05:00:00'));
        $this->list = [
            ['shift_id' => 1401412, 'shift_staff_name' => 'op', 'shift_start_time' => '2026-11-11 20:00:00', 'shift_end_time' => '2026-11-12 08:00:00', 'total_amount' => '2765917.6', 'cash' => '2765917.6', 'credit_card' => '0', 'qr' => '0'],
            ['shift_id' => 1397572, 'shift_staff_name' => 'op', 'shift_start_time' => '2026-11-10 20:00:00', 'shift_end_time' => '2026-11-11 08:00:00', 'total_amount' => '1.0000000000044E+18', 'cash' => '1.0000000000044E+18', 'credit_card' => '0', 'qr' => '0'],
        ];
        $this->shift('-7347738462', '2026-11-11 20:00:00', null, 100);   // the same shift while it was open
        $this->assertSame(1, app(ShiftSync::class)->sync($this->club));
        $this->assertSame(['1401412'], DB::table('icafe_shifts')->pluck('shift_id')->all());
    }

    public function test_parallel_operator_account_is_judged_against_its_own_shifts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00'));
        foreach (range(2, 8) as $i) {
            $d = CarbonImmutable::parse('2026-11-12', 'Asia/Tashkent')->subDays($i)->toDateString();
            $this->shift("main$i", "$d 08:00:00", "$d 20:00:00", 3500000, 'operator1');
            $this->shift("side$i", "$d 07:00:00", CarbonImmutable::parse($d)->addDay()->toDateString().' 07:00:00', 20000, 'operator_02');
        }
        $this->shift('side-new', '2026-11-11 07:00:00', '2026-11-12 06:58:00', 30000, 'operator_02');
        $this->assertSame([], app(CashControl::class)->evaluate($this->club));
    }

    public function test_cash_control_flags_low_total_short_shift_and_refunds_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00'));
        foreach (range(2, 8) as $i) {
            $d = CarbonImmutable::parse('2026-11-12', 'Asia/Tashkent')->subDays($i)->toDateString();
            $this->shift("b$i", "$d 08:00:00", "$d 20:00:00", 1000000);
        }
        // Night shifts must not be part of the morning baseline.
        $this->shift('n1', '2026-11-08 20:00:00', '2026-11-09 08:00:00', 50000);
        // Yesterday morning: 40% of usual, closed after 6 h, 10% refunds.
        $this->refund = 40000;
        $this->shift('900', '2026-11-11 08:00:00', '2026-11-11 14:00:00', 400000, 'Азиз');

        $raised = app(CashControl::class)->evaluate($this->club);
        $this->assertCount(1, $raised);
        $this->assertSame(['low_total', 'short', 'refunds'], $raised[0]['reasons']);
        $event = DB::table('outbox_events')->where('kind', 'cash_anomaly')->first();
        $msg = json_decode($event->payload, true)['message'];
        $this->assertStringContainsString('Азиз', $msg);
        $this->assertStringContainsString('40%', $msg);
        $this->assertStringContainsString('6 ч вместо обычных 12 ч', $msg);
        // Evaluated once; baseline shifts from the first sync are not news.
        $this->assertSame([], app(CashControl::class)->evaluate($this->club));
        $this->assertSame(0, DB::table('icafe_shifts')->whereNotNull('ended_at')->whereNull('checked_at')->count());
    }

    public function test_normal_shift_is_quiet_and_forgotten_open_shift_alerts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00'));
        foreach (range(2, 8) as $i) {
            $d = CarbonImmutable::parse('2026-11-12', 'Asia/Tashkent')->subDays($i)->toDateString();
            $this->shift("b$i", "$d 08:00:00", "$d 20:00:00", 1000000);
        }
        $this->shift('ok', '2026-11-11 08:00:00', '2026-11-11 20:00:00', 950000);
        $this->shift('open', '2026-11-11 08:00:00', null, 10);
        $raised = app(CashControl::class)->evaluate($this->club);
        $this->assertCount(1, $raised);
        $this->assertSame(['long_open'], $raised[0]['reasons']);
        $this->assertSame([], app(CashControl::class)->evaluate($this->club));
    }

    public function test_occupancy_heatmap_and_daily_digest(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-12 04:00:00'));   // 09:00 in Tashkent
        // Wednesday 2026-11-11 21:00 local = 16:00 UTC: 40 of 50 busy; 10:00 local: 5 of 50.
        foreach ([0, 5, 10] as $m) {
            DB::table('hall_occupancy')->insert(['club_id' => $this->club->id, 'sampled_at' => CarbonImmutable::parse('2026-11-11 16:00:00')->addMinutes($m), 'busy' => 40, 'online' => 50, 'total' => 50]);
            DB::table('hall_occupancy')->insert(['club_id' => $this->club->id, 'sampled_at' => CarbonImmutable::parse('2026-11-11 05:00:00')->addMinutes($m), 'busy' => 5, 'online' => 50, 'total' => 50]);
        }
        $h = app(Occupancy::class)->heatmap($this->club);
        $this->assertSame(80, $h['grid'][3][21]);
        $this->assertSame(10, $h['grid'][3][10]);
        $this->assertSame(['dow' => 3, 'hour' => 21, 'pct' => 80], $h['peak']);

        $this->shift('a', '2026-11-11 08:00:00', '2026-11-11 20:00:00', 1500000, 'Азиз');
        $this->shift('b', '2026-11-11 20:00:00', '2026-11-12 08:00:00', 900000, 'Диля');
        $this->shift('w', '2026-11-04 08:00:00', '2026-11-04 20:00:00', 2000000);
        DB::table('game_usage_hourly')->insert(['club_id' => $this->club->id, 'hour' => '2026-11-11 15:00:00+00', 'game' => 'Dota 2', 'minutes' => 600]);
        $d = app(DailyDigest::class)->build($this->club, CarbonImmutable::parse('2026-11-11', 'Asia/Tashkent'));
        $this->assertEquals(2400000, $d['revenue']);
        $this->assertSame(2, $d['shifts']);
        $this->assertSame(45, $d['occupancy']['avg']);
        $text = DailyDigest::telegram($d);
        $this->assertStringContainsString('2 400 000 UZS', $text);
        $this->assertStringContainsString('▲ +20%', $text);
        $this->assertStringContainsString('лучшая — Азиз', $text);
        $this->assertStringContainsString('Dota 2 10 ч', $text);
        $this->assertStringContainsString('пик 80% в 21:00', $text);

        $spec = User::factory()->create(['role' => 'specialist']);
        $this->actingAs($spec)->getJson("/clubs/{$this->club->id}/occupancy")->assertForbidden();
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->getJson("/clubs/{$this->club->id}/occupancy")->assertOk()->assertJsonPath('peak.pct', 80);
    }

    public function test_channel_subscriptions_can_be_edited(): void
    {
        $c = new TelegramChannel();
        $c->club_id = $this->club->id; $c->name = 'Владелец'; $c->bot_token = '123:abc'; $c->chat_id = '42'; $c->kinds = ['test'];
        $c->save();
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->post("/integrations/channels/{$c->id}/kinds", ['kinds' => ['daily_digest', 'cash_anomaly']])->assertSessionHasNoErrors();
        $this->assertSame(['daily_digest', 'cash_anomaly'], $c->fresh()->kinds);
        $this->assertTrue($c->fresh()->accepts('daily_digest'));
        $this->post("/integrations/channels/{$c->id}/kinds", ['kinds' => ['nope']])->assertSessionHasErrors('kinds.0');
        $spec = User::factory()->create(['role' => 'specialist']);
        $this->actingAs($spec)->post("/integrations/channels/{$c->id}/kinds", ['kinds' => ['test']])->assertForbidden();
    }
}
