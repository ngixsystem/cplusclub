<?php
namespace Tests\Feature;
use App\Models\{User,Club,Ticket,Attachment,Equipment};
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

    public function test_quick_cleaning_creates_closed_ticket_with_photos(): void {
        Storage::fake('local');
        $u=User::factory()->create(['role'=>'specialist']);$c=Club::create(['name'=>'Club','address'=>'Test']);$u->clubs()->attach($c);
        $pc=new Equipment();$pc->club_id=$c->id;$pc->name='PC07';$pc->type='pc';$pc->save();
        $photo=fn($n)=>UploadedFile::fake()->createWithContent($n.'.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4XcAAAAASUVORK5CYII='));
        $base=['club_id'=>$c->id,'equipment_id'=>$pc->id,'work_result'=>"Вентиляторы очищены\nРадиаторы очищены",'verification_result'=>'Запуск проверен'];
        // Photos before and after are both required.
        $this->actingAs($u)->postJson('/tickets/cleaning',$base+['before'=>[$photo('b')]])->assertUnprocessable()->assertJsonValidationErrors('after');
        $this->assertSame(0,Ticket::count());
        $this->post('/tickets/cleaning',$base+['before'=>[$photo('b1'),$photo('b2')],'after'=>[$photo('a1')]])->assertRedirect();
        $t=Ticket::firstOrFail();
        $this->assertSame('closed',$t->status);$this->assertSame('Чистка ПК',$t->category);$this->assertSame($u->id,$t->assignee_id);
        $this->assertSame('Плановая чистка ПК PC07',$t->description);$this->assertSame('Плановая чистка',$t->cause);
        $this->assertNotNull($t->closed_at);$this->assertNotNull($t->resolved_at);
        $this->assertSame(2,Attachment::where('ticket_id',$t->id)->where('stage','before')->count());
        $this->assertSame(1,Attachment::where('ticket_id',$t->id)->where('stage','after')->count());
        foreach(Attachment::all() as $a) Storage::disk('local')->assertExists($a->path);
        $this->assertDatabaseHas('ticket_events',['ticket_id'=>$t->id,'from_status'=>'new','to_status'=>'closed']);
        // Another club's PC and representatives are refused.
        $other=Club::create(['name'=>'Other','address'=>'X']);$pc2=new Equipment();$pc2->club_id=$other->id;$pc2->name='X1';$pc2->type='pc';$pc2->save();
        $this->postJson('/tickets/cleaning',['club_id'=>$c->id,'equipment_id'=>$pc2->id]+$base+['before'=>[$photo('b')],'after'=>[$photo('a')]])->assertStatus(422);
        $this->postJson('/tickets/cleaning',['club_id'=>$other->id,'equipment_id'=>$pc2->id]+$base+['before'=>[$photo('b')],'after'=>[$photo('a')]])->assertForbidden();
        $rep=User::factory()->create(['role'=>'representative']);$rep->clubs()->attach($c);
        $this->actingAs($rep)->postJson('/tickets/cleaning',$base+['before'=>[$photo('b')],'after'=>[$photo('a')]])->assertForbidden();
        $this->assertSame(1,Ticket::count());
    }
}
