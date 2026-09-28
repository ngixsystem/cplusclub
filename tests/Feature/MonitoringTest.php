<?php
namespace Tests\Feature;
use App\Models\{Club,Equipment,TelegramChannel};
use App\Domain\Notifications\{DispatchOutbox,TelegramTransport};
use App\Jobs\DeliverTelegram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Queue,Http};
use Illuminate\Support\Str;
use Tests\TestCase;
class MonitoringTest extends TestCase {
    use RefreshDatabase;
    private function setupAgent():array {
        $club=Club::create(['name'=>'Lab','address'=>'Tashkent']);$pc=Equipment::create(['club_id'=>$club->id,'name'=>'PC01']);$id=(string)Str::uuid();
        DB::table('agents')->insert(['id'=>$id,'club_id'=>$club->id,'name'=>'test','token_hash'=>hash('sha256','test-secret')]);DB::table('agent_equipment')->insert(['agent_id'=>$id,'equipment_id'=>$pc->id]);
        DB::table('monitor_rules')->insert(['club_id'=>$club->id,'metric'=>'cpu_temp','trigger_value'=>85,'recovery_value'=>75,'hold_seconds'=>120]);return [$club,$pc,$id];
    }
    private function packet(int $id,?float $temp):array {
        $sample=['equipment_id'=>$id,'observed_at'=>now()->toIso8601String(),'sensor_status'=>[]];
        foreach(['cpu_temp','gpu_temp','cpu_load','gpu_load','ram_used_percent','disk_free_percent'] as $m){$sample[$m]=$m==='cpu_temp'?$temp:null;$sample['sensor_status'][$m]=$sample[$m]===null?'unavailable':'ok';}
        return ['batch_id'=>(string)Str::uuid(),'samples'=>[$sample]];
    }
    public function test_ingestion_dedup_missing_sensor_scope_and_revocation():void {
        [$club,$pc,$agent]=$this->setupAgent();$p=$this->packet($pc->id,null);
        $this->withToken('test-secret')->postJson('/api/v1/telemetry',$p)->assertOk()->assertJson(['duplicate'=>false]);
        $this->withToken('test-secret')->postJson('/api/v1/telemetry',$p)->assertOk()->assertJson(['duplicate'=>true]);
        $this->assertDatabaseCount('telemetry_samples',1);$this->assertNull(DB::table('telemetry_samples')->value('cpu_temp'));
        $other=Club::create(['name'=>'Other','address'=>'Other']);$foreign=Equipment::create(['club_id'=>$other->id,'name'=>'PC02']);
        $this->withToken('test-secret')->postJson('/api/v1/telemetry',$this->packet($foreign->id,90))->assertForbidden();
        DB::table('agents')->where('id',$agent)->update(['revoked_at'=>now()]);
        $this->withToken('test-secret')->postJson('/api/v1/telemetry',$this->packet($pc->id,90))->assertUnauthorized();
    }
    public function test_alarm_outbox_delivery_and_recovery_end_to_end():void {
        Queue::fake();[$club,$pc]=$this->setupAgent();$channel=new TelegramChannel();$channel->club_id=$club->id;$channel->name='Lab';$channel->bot_token='123:fake';$channel->chat_id='123';$channel->kinds=['monitor_problem','monitor_recovered'];$channel->save();
        $this->withToken('test-secret')->postJson('/api/v1/telemetry',$this->packet($pc->id,90))->assertOk();$this->assertDatabaseCount('outbox_events',0);
        $this->travel(121)->seconds();$this->withToken('test-secret')->postJson('/api/v1/telemetry',$this->packet($pc->id,92))->assertOk();$this->assertDatabaseCount('outbox_events',1);
        app(DispatchOutbox::class)->execute();app(DispatchOutbox::class)->execute();$this->assertDatabaseCount('deliveries',1);
        $delivery=DB::table('deliveries')->value('id');$job=new DeliverTelegram($delivery);$job->handle(app(TelegramTransport::class));$job->handle(app(TelegramTransport::class));
        $this->assertDatabaseHas('deliveries',['id'=>$delivery,'status'=>'sent']);$this->assertDatabaseCount('delivery_attempts',1);
        $this->travel(60)->seconds();$this->withToken('test-secret')->postJson('/api/v1/telemetry',$this->packet($pc->id,70))->assertOk();$this->assertDatabaseCount('outbox_events',2);
    }
    public function test_telegram_429_and_connection_failure_are_safe():void {
        config(['services.telegram.transport'=>'telegram']);Http::preventStrayRequests();$c=new TelegramChannel();$c->bot_token='123:secret';$c->chat_id='456';
        Http::fake(['*'=>Http::response(['ok'=>false,'parameters'=>['retry_after'=>42]],429)]);
        $result=app(TelegramTransport::class)->send($c,'test');$this->assertSame(42,$result['retry_after']);
        Http::fake(['*'=>Http::failedConnection()]);$result=app(TelegramTransport::class)->send($c,'test');$this->assertTrue($result['ambiguous']);$this->assertArrayNotHasKey('token',$result);
    }
}
