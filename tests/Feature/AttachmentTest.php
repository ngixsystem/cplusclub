<?php
namespace Tests\Feature;
use App\Models\{Club,Equipment,Attachment,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
class AttachmentTest extends TestCase {
    use RefreshDatabase;
    public function test_photo_preview_is_inline_private_and_scoped_to_membership():void {
        Storage::fake('local');
        $bytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4XcAAAAASUVORK5CYII=');
        Storage::disk('local')->put('attachments/photo.png',$bytes);
        $owner=User::factory()->create(['role'=>'owner']);$u=User::factory()->create(['role'=>'representative']);
        $c=Club::create(['name'=>'Photos','address'=>'Test']);
        $ticket=\App\Models\Ticket::create(['club_id'=>$c->id,'initiator_id'=>$owner->id,'category'=>'Test','description'=>'Photo']);
        $a=new Attachment();$a->club_id=$c->id;$a->ticket_id=$ticket->id;$a->uploaded_by=$owner->id;$a->path='attachments/photo.png';$a->name='photo.png';$a->mime='image/png';$a->size=strlen($bytes);$a->save();
        $url='/attachments/'.$a->id.'/preview';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($u)->get($url)->assertForbidden();
        $u->clubs()->attach($c);
        $response=$this->get($url)->assertOk()->assertHeader('Content-Type','image/png')->assertHeader('X-Content-Type-Options','nosniff');
        $this->assertStringStartsWith('inline',$response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
        $this->assertSame($bytes,$response->streamedContent());
        Storage::disk('local')->put($a->path,'<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $this->get($url)->assertStatus(415);
        Storage::disk('local')->delete($a->path);
        $this->get($url)->assertNotFound();
    }
    public function test_private_attachment_cannot_be_downloaded_by_foreign_member():void {
        Storage::fake('local');Storage::disk('local')->put('attachments/test.txt','private');
        $u=User::factory()->create(['role'=>'representative']);$owner=User::factory()->create(['role'=>'owner']);
        $c=Club::create(['name'=>'Lab','address'=>'Tashkent']);$e=Equipment::create(['club_id'=>$c->id,'name'=>'PC']);
        $a=new Attachment();$a->club_id=$c->id;$a->equipment_id=$e->id;$a->uploaded_by=$owner->id;$a->path='attachments/test.txt';$a->name='test.txt';$a->mime='text/plain';$a->size=7;$a->save();
        $this->actingAs($u)->get('/attachments/'.$a->id)->assertForbidden();
        $u->clubs()->attach($c->id);$this->actingAs($u)->get('/attachments/'.$a->id)->assertDownload('test.txt');
    }
}
