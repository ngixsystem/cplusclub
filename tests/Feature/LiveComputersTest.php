<?php
namespace Tests\Feature;
use App\Models\{Club,Equipment,User};
use App\Domain\Monitoring\{ImportIcafeComputers,LiveComputers,ClubTemplate,EvaluateSample};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http,DB};
use Tests\TestCase;

class LiveComputersTest extends TestCase {
    use RefreshDatabase;
    private function club():Club {
        $c=Club::create(['name'=>'Lab','address'=>'Test']);$c->icafe_license=123;$c->icafe_token='private-token';$c->save();return $c->fresh();
    }
    public function test_import_is_idempotent_club_scoped_and_preserves_existing_equipment():void {
        $c=$this->club();$e=Equipment::create(['club_id'=>$c->id,'name'=>'01','type'=>'pc','cpu'=>'Keep CPU']);
        Http::fake(['*'=>Http::response(['code'=>200,'data'=>[
            ['pc_icafe_id'=>123,'pc_console_type'=>0,'pc_name'=>'01','pc_mac'=>'','pc_ip'=>'192.168.1.1'],
            ['pc_icafe_id'=>123,'pc_console_type'=>0,'pc_name'=>'02','pc_mac'=>'11-22-33-44-55-66'],
            ['pc_icafe_id'=>999,'pc_console_type'=>0,'pc_name'=>'Foreign'],
            ['pc_icafe_id'=>123,'pc_console_type'=>5,'pc_name'=>'Console'],
        ]])]);
        $service=app(ImportIcafeComputers::class);$first=$service->execute($c);$second=$service->execute($c);
        $this->assertSame(1,$first['created']);$this->assertSame(1,$first['linked']);$this->assertSame(0,$second['created']);
        $this->assertDatabaseCount('equipment',2);$this->assertSame('Keep CPU',$e->fresh()->cpu);
        $this->assertSame('01',$e->fresh()->icafe_pc_name);$this->assertDatabaseCount('monitor_rules',2);
    }
    public function test_boundaries_missing_and_partial_sensors():void {
        foreach([[49,49,'normal'],[50,40,'warning'],[79,50,'warning'],[79.01,40,'critical'],[null,null,'unknown'],[40,null,'unknown'],[null,80,'critical'],[0,0,'normal']] as [$cpu,$gpu,$expected])
            $this->assertSame($expected,LiveComputers::severity($cpu,$gpu));
    }
    public function test_live_endpoint_scopes_access_and_hides_old_or_offline_temperatures():void {
        $c=$this->club();$user=User::factory()->create(['role'=>'representative']);
        $url='/monitoring/clubs/'.$c->id;$this->getJson($url)->assertUnauthorized();$this->actingAs($user)->getJson($url)->assertForbidden();$user->clubs()->attach($c);
        foreach(['01','02','03'] as $name){$e=Equipment::create(['club_id'=>$c->id,'name'=>$name]);$e->icafe_pc_name=$name;$e->save();DB::table('telemetry_samples')->insert(['equipment_id'=>$e->id,'observed_at'=>$name==='03'?now()->subMinutes(4):now(),'cpu_temp'=>80,'gpu_temp'=>45,'sensor_status'=>json_encode(['cpu_temp'=>'ok','gpu_temp'=>'ok'])]);}
        Http::fake(['*'=>Http::response(['code'=>200,'data'=>[['pc_name'=>'01','is_connected'=>1],['pc_name'=>'03','is_connected'=>1]]])]);
        $this->getJson($url)->assertOk()->assertJsonPath('pcs.0.severity','critical')->assertJsonPath('pcs.1.severity','offline')->assertJsonPath('pcs.1.cpu_temp',null)->assertJsonPath('pcs.2.severity','unknown')->assertJsonPath('pcs.2.cpu_temp',null);
        $this->postJson($url.'/import')->assertForbidden();$this->postJson($url.'/template',['warning'=>50,'critical'=>79,'hold_seconds'=>0])->assertForbidden();
    }
    public function test_shared_template_triggers_both_sensors_only_above_79():void {
        $c=$this->club();$e=Equipment::create(['club_id'=>$c->id,'name'=>'01']);app(ClubTemplate::class)->save($c,50,79,0);
        $eval=app(EvaluateSample::class);
        DB::transaction(fn()=>$eval->execute($c->id,$e->id,['observed_at'=>now()->toIso8601String(),'cpu_temp'=>79,'gpu_temp'=>79]));
        $this->assertSame(0,DB::table('alerts')->where('state','active')->count());
        DB::transaction(fn()=>$eval->execute($c->id,$e->id,['observed_at'=>now()->addSecond()->toIso8601String(),'cpu_temp'=>80,'gpu_temp'=>80]));
        $this->assertSame(2,DB::table('alerts')->where('state','active')->count());
        DB::transaction(fn()=>$eval->execute($c->id,$e->id,['observed_at'=>now()->addSeconds(2)->toIso8601String(),'cpu_temp'=>49,'gpu_temp'=>49]));
        $this->assertSame(0,DB::table('alerts')->where('state','active')->count());
    }
}
