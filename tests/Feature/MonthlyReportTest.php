<?php

namespace Tests\Feature;

use App\Domain\Reports\MonthlyReport;
use App\Models\{Club, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(Club $c, string $created, ?string $resolved, array $extra = []): void
    {
        $u = User::factory()->create(['role' => 'lead']);
        DB::table('tickets')->insert(['club_id' => $c->id, 'category' => 'Сеть', 'description' => 'x', 'priority' => 'normal', 'initiator_id' => $u->id,
            'status' => $resolved ? 'resolved' : 'working', 'created_at' => $created, 'updated_at' => $created, 'resolved_at' => $resolved,
            'work_result' => 'w', 'verification_result' => 'v', 'cause' => 'c', 'solution' => 's'] + $extra);
    }

    public function test_month_figures_use_club_time_and_only_that_club(): void
    {
        $club = Club::create(['name' => 'SPOT', 'address' => 'x']);
        $other = Club::create(['name' => 'Other', 'address' => 'y']);
        // 30 Sep 21:00 UTC is already 1 Oct in Tashkent.
        $this->ticket($club, '2026-09-30 21:00:00+00', '2026-09-30 22:00:00+00');
        $this->ticket($club, '2026-10-10 10:00:00+00', '2026-10-11 09:00:00+00', ['rating' => 4, 'rated_at' => '2026-10-11 10:00:00+00']);
        $this->ticket($club, '2026-10-20 10:00:00+00', null);
        $this->ticket($club, '2026-09-30 18:00:00+00', null); // still September locally
        $this->ticket($other, '2026-10-05 10:00:00+00', null);

        $r = app(MonthlyReport::class)->build($club, '2026-10');
        $this->assertSame([3, 2, 33, 67], [$r['tickets']['created'], $r['tickets']['closed'], $r['tickets']['within_2h'], $r['tickets']['within_24h']]);
        $this->assertSame(2, $r['tickets']['open_at_end'], 'September ticket and October one still open');
        $this->assertSame(4.0, $r['rating']['avg']);
        $this->assertSame('Октябрь 2026', $r['label']);
        $this->assertStringContainsString('Решено за 2 часа: 33%', MonthlyReport::telegram($r));
    }

    public function test_representative_sees_only_own_club(): void
    {
        $this->withoutVite();
        $mine = Club::create(['name' => 'Mine', 'address' => 'x']);
        $foreign = Club::create(['name' => 'Foreign', 'address' => 'y']);
        $rep = User::factory()->create(['role' => 'representative']);
        $rep->clubs()->attach($mine->id);
        $this->actingAs($rep)->get('/reports/monthly?month=2026-10')->assertOk()->assertSee('Mine');
        $this->get('/reports/monthly?club_id='.$foreign->id)->assertForbidden();
        $this->get('/reports/monthly?month=2026-13')->assertSessionHasErrors('month');
    }
}
