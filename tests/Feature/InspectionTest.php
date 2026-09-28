<?php
namespace Tests\Feature;
use App\Domain\Inspections\CompleteInspection;
use App\Models\{Club,Equipment,Visit,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class InspectionTest extends TestCase {
    use RefreshDatabase;
    public function test_skipping_preserves_previous_inspection_and_visit_cannot_skip_pending_pc():void {
        $user=User::factory()->create(['role'=>'owner']);$club=Club::create(['name'=>'Lab','address'=>'Tashkent']);
        $pc=Equipment::create(['club_id'=>$club->id,'name'=>'PC','last_inspection_date'=>'2026-01-31','next_inspection_date'=>'2026-03-31']);
        $visit=Visit::create(['club_id'=>$club->id,'specialist_id'=>$user->id,'planned_date'=>now()->toDateString()]);
        $i=$visit->inspections()->create(['club_id'=>$club->id,'equipment_id'=>$pc->id]);
        $this->actingAs($user)->post('/visits/'.$visit->id.'/complete')->assertUnprocessable();
        $newDate=now()->addWeek()->toDateString();app(CompleteInspection::class)->execute($user,$i->id,['status'=>'skipped','skip_reason'=>'Клиент играет','rescheduled_date'=>$newDate]);
        $this->assertSame('2026-01-31',$pc->fresh()->last_inspection_date);$this->assertSame($newDate,$pc->fresh()->next_inspection_date);
        $this->actingAs($user)->post('/visits/'.$visit->id.'/complete')->assertRedirect();$this->assertSame('completed',$visit->fresh()->status);
    }
}
