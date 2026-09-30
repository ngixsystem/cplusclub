<?php
namespace Tests\Feature;

use App\Models\{User,Club,Ticket};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;
    private function data(array $extra=[]): array
    {
        return [...['name'=>'Client','email'=>'client@example.test','password'=>'abc123','role'=>'representative','club_ids'=>[],'active'=>true],...$extra];
    }
    public function test_six_character_password_create_edit_and_optional_password(): void
    {
        $this->actingAs(User::factory()->create(['role'=>'owner']));
        $this->postJson('/users',$this->data(['password'=>'12345']))->assertUnprocessable();
        $this->post('/users',$this->data())->assertRedirect();
        $u=User::where('email','client@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('abc123',$u->password));$hash=$u->password;
        $c=Club::create(['name'=>'Client club','address'=>'Test']);
        $this->post('/users/'.$u->id,$this->data(['name'=>'Updated','email'=>'updated@example.test','password'=>'','role'=>'specialist','club_ids'=>[$c->id]]))->assertRedirect();
        $this->assertSame($hash,$u->fresh()->password);$this->assertSame('specialist',$u->fresh()->role);
        $this->assertTrue($u->clubs()->whereKey($c->id)->exists());
        $this->post('/users/'.$u->id,$this->data(['password'=>'new123']))->assertRedirect();
        $this->assertTrue(Hash::check('new123',$u->fresh()->password));
    }
    public function test_deletion_preserves_work_history_and_prevents_login(): void
    {
        $admin=User::factory()->create(['role'=>'owner']);$u=User::factory()->create(['password'=>'abc123']);
        $club=Club::create(['name'=>'Club','address'=>'Test']);$u->clubs()->attach($club);
        $ticket=Ticket::create(['club_id'=>$club->id,'initiator_id'=>$u->id,'assignee_id'=>$u->id,'category'=>'Test','description'=>'Keep history']);
        $this->actingAs($admin)->delete('/users/'.$u->id)->assertRedirect();
        $this->assertSoftDeleted($u);$this->assertNull(User::find($u->id));
        $this->assertSame($u->name,$ticket->fresh()->assignee->name);
        $this->assertDatabaseHas('tickets',['id'=>$ticket->id,'initiator_id'=>$u->id]);
        $this->assertFalse($u->clubs()->exists());
        $this->post('/logout');
        $this->post('/login',['email'=>$u->email,'password'=>'abc123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }
    public function test_owner_only_and_self_protection_and_duplicate_email(): void
    {
        $admin=User::factory()->create(['role'=>'owner']);$u=User::factory()->create();
        $this->actingAs($u)->deleteJson('/users/'.$admin->id)->assertForbidden();
        $this->postJson('/users/'.$admin->id,$this->data())->assertForbidden();
        $this->postJson('/users',$this->data())->assertForbidden();
        $this->actingAs($admin)->deleteJson('/users/'.$admin->id)->assertUnprocessable();
        $this->postJson('/users/'.$admin->id,$this->data(['email'=>$admin->email,'role'=>'representative']))->assertUnprocessable();
        $this->postJson('/users/'.$admin->id,$this->data(['email'=>$admin->email,'role'=>'owner','active'=>false]))->assertUnprocessable();
        $this->postJson('/users/'.$u->id,$this->data(['email'=>$admin->email]))->assertUnprocessable();
    }
}
