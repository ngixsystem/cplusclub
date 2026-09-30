<?php

namespace Tests\Feature;

use App\Domain\Icafe\{Client, Dashboard};
use App\Models\{Club, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Http};
use Tests\TestCase;

class IcafeDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function club(): Club
    {
        $club = Club::create(['name'=>'Test club','address'=>'Test','timezone'=>'Asia/Tashkent']);
        $club->icafe_license = 123;
        $club->icafe_token = str_repeat('secret-', 8);
        $club->save();
        return $club;
    }

    private function fake(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/reports/shiftList*' => Http::response(['code'=>200,'data'=>[
                ['shift_id'=>-12,'shift_staff_name'=>'Operator','shift_start_time'=>'2026-09-30 07:00:00','shift_end_time'=>'-', 'total_amount'=>100,'cash'=>80,'credit_card'=>15,'qr'=>5],
            ]]),
            '*/pcs' => Http::response(['code'=>200,'data'=>[
                ['pc_icafe_id'=>123,'pc_name'=>'01','pc_console_type'=>0,'pc_in_using'=>1],
                ['pc_icafe_id'=>123,'pc_name'=>'02','pc_console_type'=>0,'pc_in_using'=>0],
                ['pc_icafe_id'=>999,'pc_name'=>'03','pc_console_type'=>0,'pc_in_using'=>1],
                ['pc_icafe_id'=>123,'pc_name'=>'PS5','pc_console_type'=>5,'pc_in_using'=>1],
            ]]),
            '*/onlinePcList' => Http::response(['code'=>200,'data'=>[['pc_name'=>'01','is_connected'=>1],['pc_name'=>'02','is_connected'=>0],['pc_name'=>'03','is_connected'=>1]]]),
            '*/reports/shiftDetail/*' => Http::response(['code'=>200,'data'=>['cash_sales'=>80,'cash_refund'=>-5]]),
            '*/reports/reportChart*' => Http::response(['code'=>200,'data'=>['categories'=>['07','08'],'series'=>[['name'=>'Cash','data'=>[40,60]],['name'=>'Total','data'=>[40,60]]]]]),
        ]);
    }

    public function test_auth_membership_and_financial_role_boundaries(): void
    {
        $club=$this->club(); $this->getJson('/clubs/'.$club->id.'/dashboard')->assertUnauthorized();
        $user=User::factory()->create(['role'=>'representative']);
        $this->actingAs($user)->getJson('/clubs/'.$club->id.'/dashboard')->assertForbidden();
        $user->clubs()->attach($club);
        $this->fake();
        $this->getJson('/clubs/'.$club->id.'/dashboard')->assertOk()->assertJsonPath('online_pcs',1)->assertJsonPath('total_pcs',2)->assertJsonPath('shifts.0.end',null);
        $this->post('/clubs/'.$club->id.'/icafe',[])->assertForbidden();
        $user->role='specialist';$user->save();
        $this->actingAs($user->fresh())->getJson('/clubs/'.$club->id.'/dashboard')->assertForbidden();
        $user->role='representative';$user->active=false;$user->save();
        $this->actingAs($user->fresh())->getJson('/clubs/'.$club->id.'/dashboard')->assertForbidden();
    }

    public function test_token_is_encrypted_hidden_and_connection_failure_preserves_it(): void
    {
        $club=$this->club();
        $this->assertNotSame($club->icafe_token,$club->getRawOriginal('icafe_token'));
        $this->assertArrayNotHasKey('icafe_token',$club->toArray());
        $this->actingAs(User::factory()->create(['role'=>'owner']));
        Http::fake(['*'=>Http::response(['code'=>400,'data'=>[]])]);
        $this->postJson('/clubs/'.$club->id.'/icafe',['icafe_license'=>123,'icafe_token'=>str_repeat('new-token',8),'icafe_currency'=>'UZS'])->assertUnprocessable();
        $this->assertSame($club->icafe_token,$club->fresh()->icafe_token);
    }

    public function test_stale_snapshot_survives_upstream_failure_and_is_throttled(): void
    {
        $club=$this->club();$this->fake();$service=app(Dashboard::class);
        $first=$service->snapshot($club);$service->snapshot($club);Http::assertSentCount(3);
        $key=$service->key($club).':snapshot';Cache::put($key,[...$first,'checked_at'=>time()-60],600);
        Http::fake(['*'=>Http::failedConnection()]);
        $stale=$service->snapshot($club);
        $this->assertTrue($stale['stale']);$this->assertSame($first['shifts'],$stale['shifts']);
        $this->assertSame($first['updated_at'],$stale['updated_at']);
    }

    public function test_detail_uses_total_without_double_counting_and_rejects_unknown_shift(): void
    {
        $club=$this->club();$this->fake();$user=User::factory()->create(['role'=>'representative']);$user->clubs()->attach($club);
        $this->actingAs($user)->getJson('/clubs/'.$club->id.'/dashboard/shifts/-12')->assertOk()->assertJsonPath('chart.values',[40,60])->assertJsonPath('detail.cash_refund',-5);
        $this->getJson('/clubs/'.$club->id.'/dashboard/shifts/999')->assertNotFound();
        Http::assertSent(fn ($r) => str_contains($r->url(),'shiftList') && $r['shift_staff_name']==='all');
    }

    public function test_club_creation_checks_connection_and_does_not_flash_token(): void
    {
        $this->actingAs(User::factory()->create(['role'=>'owner']));$this->fake();
        $payload=['name'=>'Connected','address'=>'Test','timezone'=>'Asia/Tashkent','support_hours'=>'24/7','icafe_license'=>123,'icafe_token'=>str_repeat('token',10),'icafe_currency'=>'UZS'];
        $this->post('/clubs',$payload)->assertRedirect();
        $this->assertDatabaseHas('clubs',['name'=>'Connected','icafe_license'=>123]);
        $payload['icafe_license']=456;unset($payload['name']);
        $this->post('/clubs',$payload)->assertSessionHasErrors('name')->assertSessionMissing('_old_input.icafe_token');
    }

    public function test_partial_hour_missing_label_is_restored_without_changing_values(): void
    {
        $club=$this->club();
        Http::fake([
            '*/shiftDetail/*'=>Http::response(['code'=>200,'data'=>[]]),
            '*/reportChart*'=>Http::response(['code'=>200,'data'=>['categories'=>['07','08'], 'series'=>[['name'=>'Total','data'=>[10,20,30]]]]]),
        ]);
        $result=app(Dashboard::class)->detail($club,['id'=>'1','operator'=>'Test','start'=>'2026-09-30 07:25:19','end'=>'2026-09-30 09:10:15']);
        $this->assertFalse($result['stale']);
        $this->assertSame(['07','08','09'],$result['chart']['labels']);
        $this->assertSame([10,20,30],$result['chart']['values']);
    }
}
