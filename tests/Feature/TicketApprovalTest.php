<?php
namespace Tests\Feature;

use App\Models\{Club, Ticket, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TicketApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        $staff = User::factory()->create(['role'=>'specialist']);
        $client = User::factory()->create(['role'=>'representative']);
        $club = Club::create(['name'=>'Approval test','address'=>'Test']);
        $staff->clubs()->attach($club); $client->clubs()->attach($club);
        $ticket = Ticket::create(['club_id'=>$club->id,'initiator_id'=>$staff->id,'category'=>'test','description'=>'Repair']);
        $ticket->status='working';$ticket->save();
        return [$staff,$client,$ticket];
    }

    private function quote(Ticket $t): array
    {
        return ['version'=>$t->fresh()->version,'description'=>'Replace cable','amount_minor'=>12300,'currency'=>'UZS','expected_duration'=>'One day'];
    }

    public function test_approved_work_requires_customer_confirmation_and_records_history(): void
    {
        [$staff,$client,$t]=$this->scenario();$url='/tickets/'.$t->id;
        $this->actingAs($staff)->post($url.'/proposal',$this->quote($t))->assertRedirect();
        $id=DB::table('ticket_proposals')->value('id');
        $this->postJson($url.'/transition',['version'=>2,'status'=>'working'])->assertUnprocessable();
        $decision=['version'=>2,'proposal_id'=>$id,'decision'=>'approve'];
        $this->actingAs($client)->post($url.'/decision',$decision)->assertRedirect();
        $this->postJson($url.'/decision',$decision)->assertConflict();
        $this->assertDatabaseHas('ticket_proposals',['id'=>$id,'status'=>'approved','decided_by'=>$client->id,'amount_minor'=>12300]);
        $this->actingAs($staff)->post($url.'/transition',['version'=>3,'status'=>'resolved','work_result'=>'Replaced','verification_result'=>'Tested','cause'=>'Cable','solution'=>'New cable'])->assertRedirect();
        $this->postJson($url.'/transition',['version'=>4,'status'=>'closed'])->assertUnprocessable();
        $this->actingAs($client)->postJson($url.'/completion',['version'=>4,'decision'=>'rework'])->assertUnprocessable();
        $this->post($url.'/completion',['version'=>4,'decision'=>'rework','comment'=>'Test another game'])->assertRedirect();
        $this->assertNull($t->fresh()->resolved_at);
        $this->actingAs($staff)->post($url.'/transition',['version'=>5,'status'=>'resolved'])->assertRedirect();
        $this->actingAs($client)->post($url.'/completion',['version'=>6,'decision'=>'confirm'])->assertRedirect();
        $this->assertSame('closed',$t->fresh()->status);$this->assertNotNull($t->fresh()->closed_at);
        $this->assertSame(6,$t->events()->count());
    }

    public function test_rejection_and_revisions_cannot_be_bypassed(): void
    {
        [$staff,$client,$t]=$this->scenario();$url='/tickets/'.$t->id;
        $this->actingAs($staff)->post($url.'/proposal',$this->quote($t))->assertRedirect();$first=DB::table('ticket_proposals')->value('id');
        $this->actingAs($client)->postJson($url.'/decision',['version'=>2,'proposal_id'=>$first,'decision'=>'reject','comment'=>'  '])->assertUnprocessable();
        $this->post($url.'/decision',['version'=>2,'proposal_id'=>$first,'decision'=>'reject','comment'=>'Too expensive'])->assertRedirect();
        $this->actingAs($staff)->postJson($url.'/transition',['version'=>3,'status'=>'working'])->assertUnprocessable();
        $this->post($url.'/proposal',$this->quote($t))->assertRedirect();$second=DB::table('ticket_proposals')->max('id');
        $this->post($url.'/proposal',$this->quote($t))->assertRedirect();
        $this->assertDatabaseHas('ticket_proposals',['id'=>$first,'status'=>'rejected','decision_comment'=>'Too expensive']);
        $this->assertDatabaseHas('ticket_proposals',['id'=>$second,'status'=>'superseded']);
        $this->actingAs($client)->postJson($url.'/decision',['version'=>5,'proposal_id'=>$second,'decision'=>'approve'])->assertUnprocessable();
        $this->assertSame('approval',$t->fresh()->status);
    }

    public function test_only_active_club_representatives_can_decide_and_cannot_edit_work(): void
    {
        [$staff,$client,$t]=$this->scenario();$url='/tickets/'.$t->id;
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($staff)->post($url.'/proposal',$this->quote($t))->assertRedirect();
        $id=DB::table('ticket_proposals')->value('id');$decision=['version'=>2,'proposal_id'=>$id,'decision'=>'approve'];
        foreach ([$staff,User::factory()->create(['role'=>'owner']),User::factory()->create(['role'=>'representative'])] as $outsider) {
            $this->actingAs($outsider)->postJson($url.'/decision',$decision)->assertForbidden();
            $this->postJson($url.'/completion',['version'=>2,'decision'=>'confirm'])->assertForbidden();
        }
        $this->actingAs($client)->postJson($url.'/proposal',$this->quote($t))->assertForbidden();
        $this->postJson($url.'/transition',['version'=>2,'status'=>'working'])->assertForbidden();
        $client->active=false;$client->save();
        $this->actingAs($client->fresh())->postJson($url.'/decision',$decision)->assertForbidden();
        $this->assertDatabaseHas('ticket_proposals',['id'=>$id,'status'=>'pending']);
    }

    public function test_quote_validation_and_legacy_approval_require_explicit_proposal(): void
    {
        [$staff,,$t]=$this->scenario();$url='/tickets/'.$t->id;
        $this->actingAs($staff)->postJson($url.'/proposal',[...$this->quote($t),'amount_minor'=>-1])->assertUnprocessable();
        $this->postJson($url.'/proposal',[...$this->quote($t),'version'=>99])->assertConflict();
        $this->postJson($url.'/transition',['version'=>1,'status'=>'approval'])->assertUnprocessable();
        $t->status='approval';$t->save();
        $this->post($url.'/proposal',$this->quote($t))->assertRedirect();
        $this->assertDatabaseCount('ticket_proposals',1);
    }
}
