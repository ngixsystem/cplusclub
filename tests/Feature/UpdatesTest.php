<?php
namespace Tests\Feature;
use App\Domain\Updates\{CheckPublished,VersionProvider,SteamCmdProvider};
use App\Models\{Club,Equipment,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Tests\TestCase;
class UpdatesTest extends TestCase {
    use RefreshDatabase;
    public function test_published_change_is_confirmed_once_and_local_and_verification_are_separate():void {
        $club=Club::create(['name'=>'Lab','address'=>'Tashkent']);DB::table('game_subscriptions')->insert(['club_id'=>$club->id,'app_id'=>730]);
        $provider=new class implements VersionProvider {public string $build='100';public function fetch(int $id):array{return ['build_id'=>$this->build,'published_at'=>null];}};
        $service=app(CheckPublished::class);$service->execute(730,$provider);$this->assertDatabaseCount('outbox_events',0);
        $provider->build='99';$service->execute(730,$provider);$this->assertDatabaseCount('outbox_events',0);$service->execute(730,$provider);$service->execute(730,$provider);$this->assertDatabaseCount('outbox_events',1);
        $pc=Equipment::create(['club_id'=>$club->id,'name'=>'PC']);$agent=(string)Str::uuid();DB::table('agents')->insert(['id'=>$agent,'club_id'=>$club->id,'name'=>'test','token_hash'=>hash('sha256','test')]);DB::table('agent_equipment')->insert(['agent_id'=>$agent,'equipment_id'=>$pc->id]);
        $data=['equipment_id'=>$pc->id,'product'=>'730','value'=>'100'];$this->withToken('test')->postJson('/api/v1/local-version',$data)->assertOk();$this->assertDatabaseCount('outbox_events',1);
        $data['value']='99';$this->travel(31)->seconds();$this->withToken('test')->postJson('/api/v1/local-version',$data)->assertOk();$this->travel(31)->seconds();$this->withToken('test')->postJson('/api/v1/local-version',$data)->assertOk();$this->assertDatabaseCount('outbox_events',2);$this->assertDatabaseCount('version_checks',0);
        $u=User::factory()->create(['role'=>'lead']);$this->actingAs($u)->post('/updates/verify',['equipment_id'=>$pc->id,'product'=>'730','checked_value'=>'99','result'=>'Game starts successfully'])->assertRedirect();$this->assertDatabaseCount('version_checks',1);
    }
    public function test_source_timeout_preserves_last_version_and_marks_unavailable():void {
        DB::table('published_versions')->insert(['app_id'=>730,'build_id'=>'123']);Http::fake(['*'=>Http::failedConnection()]);app(CheckPublished::class)->execute(730,new SteamCmdProvider());$this->assertDatabaseHas('published_versions',['app_id'=>730,'build_id'=>'123','source_status'=>'unavailable']);
    }
}
