<?php
namespace Tests\Feature;
use App\Models\{User,ConnectionRequest};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
class ConnectionRequestTest extends TestCase {
    use RefreshDatabase;
    private function payload(): array {return ['request_id'=>(string)Str::uuid(),'name'=>'Owner','club'=>'Test club','contact'=>'test@example.test','pc_count'=>50,'plan'=>'standard','consent'=>true,'website'=>''];}
    public function test_public_submission_is_validated_and_idempotent(): void {
        $d=$this->payload();$this->postJson('/api/connection-requests',$d)->assertCreated();$this->postJson('/api/connection-requests',$d)->assertCreated();$this->assertSame(1,ConnectionRequest::count());
        foreach([['consent'=>false],['pc_count'=>0],['pc_count'=>1001],['website'=>'spam'],['plan'=>'free'],['name'=>'']] as $invalid)$this->postJson('/api/connection-requests',[...$this->payload(),...$invalid])->assertUnprocessable();
        $this->get('/connection-requests')->assertRedirect('/login');
    }
    public function test_only_active_managers_can_read_and_process_requests(): void {
        $this->postJson('/api/connection-requests',$this->payload())->assertCreated();$lead=ConnectionRequest::firstOrFail();
        foreach(['representative','specialist'] as $role){$u=User::factory()->create(['role'=>$role]);$this->actingAs($u)->get('/connection-requests')->assertForbidden();$this->post('/connection-requests/'.$lead->id,['status'=>'closed'])->assertForbidden();}
        foreach(['owner','lead'] as $role){$u=User::factory()->create(['role'=>$role]);$this->actingAs($u)->get('/connection-requests')->assertOk();$this->post('/connection-requests/'.$lead->id,['status'=>'contacted'])->assertRedirect();}
        $this->postJson('/connection-requests/'.$lead->id,['status'=>'invalid'])->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role'=>'owner','active'=>false]))->get('/connection-requests')->assertForbidden();
    }
}
