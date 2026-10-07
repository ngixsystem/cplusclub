<?php
namespace Tests\Feature;

use App\Models\{Club,Equipment,Ticket,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TicketEquipmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_computer_choices_are_scoped_and_cross_club_submission_is_rejected(): void
    {
        $u=User::factory()->create(['role'=>'specialist']);
        $a=Club::create(['name'=>'A','address'=>'Test']);$b=Club::create(['name'=>'B','address'=>'Test']);$u->clubs()->attach($a);
        $pc=Equipment::create(['club_id'=>$a->id,'name'=>'PC127','type'=>'pc']);
        Equipment::create(['club_id'=>$a->id,'name'=>'Server','type'=>'server']);
        $foreign=Equipment::create(['club_id'=>$b->id,'name'=>'PC127','type'=>'pc']);
        $this->actingAs($u)->get('/tickets')->assertInertia(fn(Assert $p)=>$p->component('Workspace',false)->has('ticketComputers',1)->where('ticketComputers.0.id',$pc->id));
        $payload=['club_id'=>$a->id,'equipment_id'=>$foreign->id,'category'=>'Test','description'=>'Broken','priority'=>'normal'];
        $this->postJson('/tickets',$payload)->assertUnprocessable();
        $this->postJson('/tickets',[...$payload,'equipment_id'=>0])->assertUnprocessable();
        $this->postJson('/tickets',[...$payload,'club_id'=>$b->id])->assertForbidden();
        $this->post('/tickets',[...$payload,'equipment_id'=>$pc->id])->assertRedirect();
        $t=Ticket::firstOrFail();$this->assertSame($pc->id,$t->equipment_id);
        $this->get('/equipment/'.$pc->id)->assertInertia(fn(Assert $p)=>$p->has('tickets.data',1)->where('tickets.data.0.id',$t->id));
        $this->get('/tickets/'.$t->id)->assertInertia(fn(Assert $p)=>$p->where('ticket.equipment.id',$pc->id));
        $this->get('/equipment/'.$foreign->id)->assertForbidden();
    }

    public function test_equipment_history_retains_closed_tickets_across_pages_and_renames(): void
    {
        $u=User::factory()->create(['role'=>'owner']);$c=Club::create(['name'=>'History','address'=>'Test']);
        $pc=Equipment::create(['club_id'=>$c->id,'name'=>'PC127']);
        for($i=0;$i<21;$i++){
            $t=Ticket::create(['club_id'=>$c->id,'equipment_id'=>$pc->id,'initiator_id'=>$u->id,'category'=>'Test','description'=>'Issue '.$i]);
            $t->status='closed';$t->work_result='Fixed';$t->verification_result='Tested';$t->cause='Cable';$t->solution='Replaced';$t->closed_at=now();$t->save();
        }
        $pc->name='Renamed PC127';$pc->save();
        $this->actingAs($u)->get('/equipment/'.$pc->id)->assertInertia(fn(Assert $p)=>$p->has('tickets.data',20)->where('tickets.total',21)->where('tickets.data.0.status','closed'));
        $this->get('/equipment/'.$pc->id.'?tickets_page=2')->assertInertia(fn(Assert $p)=>$p->has('tickets.data',1)->where('tickets.data.0.description','Issue 0'));
    }

    public function test_general_ticket_can_be_created_without_a_pc(): void
    {
        $u=User::factory()->create(['role'=>'representative']);$c=Club::create(['name'=>'Club','address'=>'Test']);$u->clubs()->attach($c);
        $this->actingAs($u)->post('/tickets',['club_id'=>$c->id,'equipment_id'=>'','category'=>'Network','description'=>'Whole club offline','priority'=>'normal'])->assertRedirect();
        $this->assertNull(Ticket::firstOrFail()->equipment_id);
    }
}
