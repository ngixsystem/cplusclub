<?php

namespace Tests\Feature;

use App\Models\{Club, Ticket, User};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_flow_open_level_and_access(): void
    {
        $now = CarbonImmutable::parse('2026-10-20 12:00', 'Asia/Tashkent');
        CarbonImmutable::setTestNow($now); \Illuminate\Support\Carbon::setTestNow($now);
        $owner = User::factory()->create(['role' => 'owner']);
        $a = Club::create(['name' => 'A', 'address' => 'x']); $b = Club::create(['name' => 'B', 'address' => 'x']);
        $make = function (Club $c, string $created, ?string $resolved = null, string $priority = 'normal') use ($owner) {
            $t = Ticket::create(['club_id' => $c->id, 'initiator_id' => $owner->id, 'category' => 'Диагностика', 'description' => 'x', 'priority' => $priority]);
            $t->created_at = CarbonImmutable::parse($created, 'Asia/Tashkent')->utc();
            if ($resolved) { $t->status = 'resolved'; $t->resolved_at = CarbonImmutable::parse($resolved, 'Asia/Tashkent')->utc(); $t->work_result = 'ok'; $t->verification_result = 'ok'; }
            $t->save();
        };
        $make($a, '2026-09-01 10:00');                          // open before the range
        $make($a, '2026-09-02 10:00', '2026-10-19 09:00');     // opened before, resolved inside
        $make($a, '2026-10-19 08:00', '2026-10-19 10:00');     // opened and resolved the same day, 120 min
        $make($b, '2026-10-20 01:00', null, 'critical');       // today, 01:00 Tashkent = previous UTC day

        $r = $this->actingAs($owner)->getJson('/tickets/stats?range=7')->assertOk()->json();
        $this->assertSame(7, $r['range']);
        $this->assertSame(['2026-10-14', '2026-10-20'], [$r['labels'][0], end($r['labels'])]);
        $this->assertSame([0, 0, 0, 0, 0, 1, 1], $r['series']['new']);
        $this->assertSame([0, 0, 0, 0, 0, 2, 0], $r['series']['closed']);
        $this->assertSame([2, 2, 2, 2, 2, 1, 2], $r['series']['open']);
        $this->assertSame(['new' => 2, 'closed' => 2, 'open_now' => 2, 'open_start' => 2], array_intersect_key($r['totals'], array_flip(['new', 'closed', 'open_now', 'open_start'])));

        $f = $this->getJson('/tickets/stats?range=7&priority=critical')->json();
        $this->assertSame(1, $f['totals']['new']);
        $this->getJson('/tickets/stats?range=999')->assertJsonPath('range', 30);

        $rep = User::factory()->create(['role' => 'representative']); $rep->clubs()->attach($b);
        $this->actingAs($rep)->getJson('/tickets/stats?range=7&club='.$a->id)->assertJsonPath('totals.new', 1)->assertJsonPath('totals.open_start', 0);
        CarbonImmutable::setTestNow(); \Illuminate\Support\Carbon::setTestNow();
    }
}
