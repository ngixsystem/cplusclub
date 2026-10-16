<?php

namespace Tests\Feature;

use App\Models\{Club, Equipment, Ticket, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OverviewTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $own = Club::create(['name' => 'Own club', 'address' => 'Test']);
        $other = Club::create(['name' => 'Other club', 'address' => 'Test']);
        $owner = User::factory()->create(['role' => 'owner']);
        foreach ([[$own, 'critical'], [$other, 'critical'], [$other, 'normal']] as [$club, $priority]) {
            Ticket::create(['club_id' => $club->id, 'category' => 'Диагностика', 'description' => $club->name.' issue',
                'priority' => $priority, 'initiator_id' => $owner->id]);
        }
        foreach ([$own, $other] as $club) {
            $pc = Equipment::create(['club_id' => $club->id, 'name' => $club->name.' PC', 'type' => 'pc',
                'next_inspection_date' => now()->subDays(3)->toDateString()]);
            DB::table('alerts')->insert(['club_id' => $club->id, 'equipment_id' => $pc->id, 'metric' => 'gpu_temp',
                'state' => 'active', 'episode' => 1, 'opened_at' => now()]);
        }
        return [$own, $other, $owner];
    }

    public function test_overview_counts_and_lists_are_limited_to_the_users_clubs(): void
    {
        [$own, , $owner] = $this->fixture();
        $rep = User::factory()->create(['role' => 'representative']);
        $rep->clubs()->attach($own);

        $this->actingAs($rep)->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Overview', false)
            ->where('overview.kpis.open', 1)
            ->has('overview.urgent', 1)->where('overview.urgent.0.club', 'Own club')
            ->where('overview.alert_count', 1)->has('overview.alerts', 1)->where('overview.alerts.0.club', 'Own club')
            ->where('overview.inspections.overdue', 1)
            ->where('attention.critical', 1)->where('attention.alerts', 1)
            ->has('financeClubs', 1)->where('financeClubs.0.name', 'Own club')
            ->where('canManage', false));

        $this->actingAs($owner)->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Overview', false)
            ->where('overview.kpis.open', 3)->where('overview.alert_count', 2)->where('attention.critical', 2)
            ->has('financeClubs', 2)->where('canManage', true));
    }

    public function test_specialist_sees_assigned_tickets_without_finance_data(): void
    {
        [, $other] = $this->fixture();
        $specialist = User::factory()->create(['role' => 'specialist']);
        Ticket::where('club_id', $other->id)->where('priority', 'normal')->update(['assignee_id' => $specialist->id]);

        $this->actingAs($specialist)->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Overview', false)
            ->where('overview.kpis.open', 1)->where('overview.alert_count', 0)
            ->has('financeClubs', 0));
    }

    public function test_guests_and_disabled_users_cannot_open_the_overview(): void
    {
        $this->get('/')->assertRedirect('/login');
        $disabled = User::factory()->create(['role' => 'owner', 'active' => false]);
        $this->actingAs($disabled)->get('/')->assertForbidden();
    }
}
