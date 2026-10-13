<?php
namespace Tests\Feature;
use App\Models\{User,Club,Ticket,Attachment};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
class CleaningTest extends TestCase {
    use RefreshDatabase;
    public function test_cleaning_needs_actual_work_and_verification_but_not_repair_fields(): void {
        $u=User::factory()->create(['role'=>'owner']);$c=Club::create(['name'=>'Clean','address'=>'Test']);
        $t=Ticket::create(['club_id'=>$c->id,'initiator_id'=>$u->id,'category'=>'Чистка ПК','description'=>'Clean']);$t->status='working';$t->save();
        $this->actingAs($u)->postJson('/tickets/'.$t->id.'/transition',['status'=>'resolved','version'=>1])->assertUnprocessable();
        $this->post('/tickets/'.$t->id.'/transition',['status'=>'resolved','version'=>1,'work_result'=>'Корпус очищен','verification_result'=>'Запуск проверен'])->assertRedirect();
        $this->assertSame('resolved',$t->fresh()->status);$this->assertSame('Корпус очищен',$t->fresh()->solution);
        $t->category='Диагностика';$t->status='working';$t->cause=null;$t->solution=null;$t->save();
        $this->postJson('/tickets/'.$t->id.'/transition',['status'=>'resolved','version'=>2,'work_result'=>'Work','verification_result'=>'Test'])->assertUnprocessable();
    }
    public function test_staged_photos_are_saved_and_cross_club_upload_is_denied(): void {
        Storage::fake('local');$u=User::factory()->create(['role'=>'specialist']);$c=Club::create(['name'=>'Club','address'=>'Test']);$u->clubs()->attach($c);
        $t=Ticket::create(['club_id'=>$c->id,'initiator_id'=>$u->id,'category'=>'Чистка ПК','description'=>'Clean']);
        $photo=fn()=>UploadedFile::fake()->createWithContent('photo.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4XcAAAAASUVORK5CYII='));
        foreach(['before','after'] as $stage){$this->actingAs($u)->post('/attachments',['type'=>'ticket','id'=>$t->id,'stage'=>$stage,'file'=>$photo()])->assertRedirect();$this->assertDatabaseHas('attachments',['ticket_id'=>$t->id,'stage'=>$stage]);}
        $this->postJson('/attachments',['type'=>'ticket','id'=>$t->id,'stage'=>'before','file'=>UploadedFile::fake()->create('doc.pdf',1,'application/pdf')])->assertUnprocessable();
        $other=User::factory()->create(['role'=>'specialist']);$this->actingAs($other)->post('/attachments',['type'=>'ticket','id'=>$t->id,'stage'=>'after','file'=>$photo()])->assertForbidden();
        $this->assertSame(2,Attachment::count());
    }
}
