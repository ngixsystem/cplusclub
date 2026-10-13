<?php
namespace Tests\Feature;
use App\Models\{User,Club,Ticket};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
class ClosedTicketReadOnlyTest extends TestCase {
    use RefreshDatabase;
    public function test_closed_tickets_are_read_only_for_both_owner_roles(): void {
        $club=Club::create(['name'=>'Club','address'=>'Test']);
        foreach(['owner','representative'] as $role){
            $u=User::factory()->create(['role'=>$role]);$u->clubs()->attach($club);
            $t=Ticket::create(['club_id'=>$club->id,'initiator_id'=>$u->id,'category'=>'Test','description'=>'Closed']);$t->status='closed';$t->work_result='Done';$t->verification_result='Tested';$t->cause='Fault';$t->solution='Fixed';$t->closed_at=now();$t->save();
            $this->actingAs($u)->get('/tickets/'.$t->id)->assertInertia(fn(Assert $p)=>$p->where('canEdit',false)->where('canAttach',false)->where('canDecide',false));
            $this->post('/tickets/'.$t->id.'/transition',['status'=>'accepted','version'=>1])->assertForbidden();
            $this->post('/tickets/'.$t->id.'/assign',['assignee_id'=>$u->id,'version'=>1])->assertForbidden();
            $this->post('/tickets/'.$t->id.'/work',['minutes'=>30,'cost_minor'=>0,'currency'=>'USD','actions'=>'Edit'])->assertForbidden();
            $this->post('/tickets/'.$t->id.'/proposal',[])->assertForbidden();
            $this->post('/tickets/'.$t->id.'/completion',[])->assertForbidden();
            $this->post('/attachments',['type'=>'ticket','id'=>$t->id,'file'=>UploadedFile::fake()->create('report.pdf',1,'application/pdf')])->assertForbidden();
            $this->assertSame('closed',$t->fresh()->status);
            $t->status='working';$t->save();
            $this->get('/tickets/'.$t->id)->assertInertia(fn(Assert $p)=>$p->where('canAttach',true)->where('canEdit',$role==='owner'));
        }
    }
}
