<?php

namespace Tests\Feature;

use App\Models\{Club, Ticket, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_and_export_respect_club_membership_for_each_role(): void
    {
        $club = Club::create(['name' => 'Allowed club', 'address' => 'Test']);
        $other = Club::create(['name' => 'Other club', 'address' => 'Test']);
        $owner = User::factory()->create(['role' => 'owner']);
        foreach ([$club, $other] as $c) {
            $ticket = Ticket::create(['club_id' => $c->id, 'category' => 'test',
                'description' => 'Report fixture', 'initiator_id' => $owner->id]);
            DB::table('work_logs')->insert(['ticket_id' => $ticket->id, 'user_id' => $owner->id,
                'minutes' => 30, 'cost_minor' => 100, 'currency' => 'UZS',
                'actions' => $c->name, 'created_at' => now()]);
        }
        foreach (['owner', 'lead', 'specialist', 'representative'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $user->clubs()->attach($club);
            $global = in_array($role, ['owner', 'lead'], true);
            $this->actingAs($user)->get('/reports')->assertOk()->assertInertia(fn (Assert $page) =>
                $page->component('Reports', false)->has('rows.data', $global ? 2 : 1)
                    ->where('totals.0.minutes', fn ($minutes) => (int) $minutes === ($global ? 60 : 30)));
            $csv = $this->get('/reports/export')->assertOk()->streamedContent();
            $this->assertStringContainsString('Allowed club', $csv);
            if ($global) $this->assertStringContainsString('Other club', $csv);
            else $this->assertStringNotContainsString('Other club', $csv);
        }
    }

    public function test_anonymous_and_disabled_users_cannot_read_reports_or_export(): void
    {
        foreach (['/reports', '/reports/export'] as $path) $this->getJson($path)->assertUnauthorized();
        $user = User::factory()->create(['role' => 'owner', 'active' => false]);
        foreach (['/reports', '/reports/export'] as $path) $this->actingAs($user)->getJson($path)->assertForbidden();
    }
}
