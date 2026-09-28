<?php
namespace Tests\Feature;
use App\Models\{Club,Equipment,Attachment,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
class AttachmentTest extends TestCase {
    use RefreshDatabase;
    public function test_private_attachment_cannot_be_downloaded_by_foreign_member():void {
        Storage::fake('local');Storage::disk('local')->put('attachments/test.txt','private');
        $u=User::factory()->create(['role'=>'representative']);$owner=User::factory()->create(['role'=>'owner']);
        $c=Club::create(['name'=>'Lab','address'=>'Tashkent']);$e=Equipment::create(['club_id'=>$c->id,'name'=>'PC']);
        $a=new Attachment();$a->club_id=$c->id;$a->equipment_id=$e->id;$a->uploaded_by=$owner->id;$a->path='attachments/test.txt';$a->name='test.txt';$a->mime='text/plain';$a->size=7;$a->save();
        $this->actingAs($u)->get('/attachments/'.$a->id)->assertForbidden();
        $u->clubs()->attach($c->id);$this->actingAs($u)->get('/attachments/'.$a->id)->assertDownload('test.txt');
    }
}
