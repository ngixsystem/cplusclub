<?php

namespace Tests\Feature;

use App\Domain\Tickets\{TicketStatus, TransitionTicket};
use App\Models\{Club, Ticket, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function setupTicket(): array
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $club = Club::create(['name' => 'Test', 'address' => 'Tashkent']);
        $ticket = Ticket::create(['club_id' => $club->id, 'category' => 'test', 'description' => 'Cannot boot', 'initiator_id' => $owner->id]);
        return [$owner, $club, $ticket];
    }

    public function test_foreign_club_ticket_is_forbidden(): void
    {
        [, , $ticket] = $this->setupTicket();
        $outsider = User::factory()->create(['role' => 'representative']);
        $this->actingAs($outsider)->get('/tickets/'.$ticket->id)->assertForbidden();
        $this->actingAs($outsider)->post('/tickets/'.$ticket->id.'/transition', ['status' => 'accepted', 'version' => 1])->assertForbidden();
    }

    public function test_complete_workflow_and_stale_version_conflict(): void
    {
        [$owner, , $ticket] = $this->setupTicket();
        $service = app(TransitionTicket::class);
        $service->execute($owner, $ticket->id, TicketStatus::Accepted, 1, []);
        $this->actingAs($owner)->post('/tickets/'.$ticket->id.'/transition', ['status' => 'working', 'version' => 1])->assertConflict();
        $service->execute($owner, $ticket->id, TicketStatus::Working, 2, []);
        $this->actingAs($owner)->postJson('/tickets/'.$ticket->id.'/transition', ['status' => 'resolved', 'version' => 3])->assertUnprocessable();
        $result = ['work_result' => 'Replaced cable', 'verification_result' => 'Boot and CS2 passed', 'cause' => 'Cable fault', 'solution' => 'New cable'];
        $service->execute($owner, $ticket->id, TicketStatus::Resolved, 3, $result);
        $service->execute($owner, $ticket->id, TicketStatus::Closed, 4, []);
        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertCount(4, $ticket->fresh()->events);
        $service->execute($owner, $ticket->id, TicketStatus::Accepted, 5, []);
        $this->assertNull($ticket->fresh()->closed_at);
    }
}
