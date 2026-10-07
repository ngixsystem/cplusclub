<?php
namespace Tests\Feature;

use App\Models\{Club, Equipment, Ticket, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClosedReportTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(Club $club, User $user, string $date, string $description='Closed work'): Ticket
    {
        $t=Ticket::create(['club_id'=>$club->id,'initiator_id'=>$user->id,'category'=>'test','description'=>$description,'assignee_id'=>$user->id]);
        DB::table('tickets')->where('id',$t->id)->update(['status'=>'closed','closed_at'=>$date,'cause'=>'Cable','solution'=>'Replaced','work_result'=>'New cable','verification_result'=>'Test passed']);
        return $t;
    }

    private function log(Ticket $t, User $user, string $currency, int $cost): void
    {
        DB::table('work_logs')->insert(['ticket_id'=>$t->id,'user_id'=>$user->id,'minutes'=>30,'cost_minor'=>$cost,'currency'=>$currency,'actions'=>'Work','created_at'=>'2026-10-01 10:00:00+00']);
    }

    public function test_zero_log_tickets_currency_totals_and_timezone_boundaries(): void
    {
        $u=User::factory()->create(['role'=>'owner']);$c=Club::create(['name'=>'Lab','address'=>'Test']);
        $first=$this->ticket($c,$u,'2026-10-06 19:00:00+00','Boundary included');
        $second=$this->ticket($c,$u,'2026-10-07 18:59:59+00','Zero logs included');
        $this->ticket($c,$u,'2026-10-07 19:00:00+00','Tomorrow excluded');
        $this->log($first,$u,'UZS',100);$this->log($first,$u,'UZS',200);$this->log($first,$u,'USD',50);
        $f='?from=2026-10-07&to=2026-10-07';
        $this->actingAs($u)->get('/reports'.$f)->assertOk()->assertInertia(fn(Assert $p)=>$p->component('Reports',false)
            ->has('rows.data',2)->where('rows.data.0.id',$second->id)->where('rows.data.0.minutes',fn($n)=>(int)$n===0)
            ->has('rows.data.0.costs',0)->where('rows.data.1.minutes',fn($n)=>(int)$n===90)
            ->has('rows.data.1.costs',2)->has('totals',2)
            ->where('totals.0.currency','USD')->where('totals.0.cost_minor',fn($n)=>(int)$n===50)
            ->where('totals.1.currency','UZS')->where('totals.1.cost_minor',fn($n)=>(int)$n===300));
        $csv=$this->get('/reports/export'.$f)->assertOk()->streamedContent();
        $this->assertStringContainsString('Boundary included',$csv);$this->assertStringContainsString('Zero logs included',$csv);$this->assertStringNotContainsString('Tomorrow excluded',$csv);
        $this->get('/reports?view=work&from=2026-10-07&to=2026-10-07')->assertInertia(fn(Assert $p)=>$p->has('rows.data',0)->has('totals',0));
    }

    public function test_filters_and_csv_cannot_escape_membership(): void
    {
        $u=User::factory()->create(['role'=>'representative']);$staff=User::factory()->create(['role'=>'specialist']);
        $a=Club::create(['name'=>'Own','address'=>'Test']);$b=Club::create(['name'=>'Foreign','address'=>'Test']);$u->clubs()->attach($a);
        $pc=Equipment::create(['club_id'=>$a->id,'name'=>'PC-A']);$other=Equipment::create(['club_id'=>$b->id,'name'=>'PC-B']);
        $t=$this->ticket($a,$staff,'2026-10-07 10:00:00+00','Visible work');$t->equipment_id=$pc->id;$t->save();
        $foreign=$this->ticket($b,$staff,'2026-10-07 10:00:00+00','Foreign secret');$this->log($foreign,$staff,'UZS',999);
        $f='?club_id='.$a->id.'&equipment_id='.$pc->id.'&assignee_id='.$staff->id;
        $this->actingAs($u)->get('/reports'.$f)->assertInertia(fn(Assert $p)=>$p->has('rows.data',1)->has('clubs',1)->has('equipment',1)->has('totals',0));
        $this->assertStringNotContainsString('Foreign secret',$this->get('/reports/export'.$f)->streamedContent());
        foreach(['/reports','/reports/export'] as $path){
            $this->getJson($path.'?club_id='.$b->id)->assertForbidden();
            $this->getJson($path.'?equipment_id='.$other->id)->assertForbidden();
            $this->getJson($path.'?from=2026-10-08&to=2026-10-07')->assertUnprocessable();
        }
        $t->status='working';$t->closed_at=null;$t->save();
        $this->get('/reports')->assertInertia(fn(Assert $p)=>$p->has('rows.data',0));
    }

    public function test_export_includes_all_pages_and_neutralizes_formula_cells(): void
    {
        $u=User::factory()->create(['role'=>'owner']);$c=Club::create(['name'=>'Export','address'=>'Test']);
        for($i=0;$i<31;$i++)$this->ticket($c,$u,'2026-10-07 10:00:00+00',$i===0?'=HYPERLINK("test")':'Export row '.$i);
        $this->actingAs($u)->get('/reports?club_id='.$c->id)->assertInertia(fn(Assert $p)=>$p->has('rows.data',30)->where('rows.total',31)->where('rows.next_page_url',fn($s)=>str_contains($s,'club_id='.$c->id)));
        $csv=$this->get('/reports/export?club_id='.$c->id.'&page=2')->assertOk()->streamedContent();
        $stream=fopen('php://temp','r+');fwrite($stream,$csv);rewind($stream);$rows=[];while(($row=fgetcsv($stream,0,',','"',''))!==false)$rows[]=$row;fclose($stream);
        $this->assertCount(32,$rows);$this->assertSame("'=HYPERLINK(\"test\")",$rows[31][5]);
    }
}
