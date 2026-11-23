<?php

namespace Tests\Feature;

use App\Models\{ApiClient, Club, User};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

class ExternalApiTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private Club $other;
    private ?array $online = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->club = Club::create(['name' => 'SPOT', 'address' => 'x']);
        $this->club->icafe_license = 555001; $this->club->icafe_token = 't'; $this->club->timezone = 'Asia/Tashkent'; $this->club->save();
        $this->other = Club::create(['name' => 'TeamPro', 'address' => 'y']);
        Http::fake(function ($req) {
            if (str_contains($req->url(), 'onlinePcList')) return Http::response(['code' => 200, 'data' => $this->online ?? [
                ['pc_name' => 'PC001', 'is_connected' => 1], ['pc_name' => 'PC002', 'is_connected' => 1], ['pc_name' => 'PC003', 'is_connected' => 0],
            ]]);
            if (str_contains($req->url(), '/pcs')) return Http::response(['code' => 200, 'data' => [
                ['pc_icafe_id' => 555001, 'pc_name' => 'PC002', 'pc_area_name' => 'VIP', 'pc_group_id' => 3, 'pc_console_type' => 0, 'pc_enabled' => 1, 'pc_in_using' => 1,
                    'status_member_id' => 77, 'status_member_account' => 'ali', 'status_connect_time_local' => '2026-11-22 15:10:00',
                    'status_disconnect_time_local' => '2026-11-22 17:10:00', 'status_connect_time_left' => '95'],
                ['pc_icafe_id' => 555001, 'pc_name' => 'PC001', 'pc_area_name' => 'Standard', 'pc_console_type' => 0, 'pc_in_using' => 0],
                ['pc_icafe_id' => 555001, 'pc_name' => 'PC003', 'pc_area_name' => 'Standard', 'pc_console_type' => 0, 'pc_in_using' => 0],
                ['pc_icafe_id' => 555001, 'pc_name' => 'PS5-1', 'pc_area_name' => 'Console', 'pc_console_type' => 1, 'pc_in_using' => 0],
                ['pc_icafe_id' => 999999, 'pc_name' => 'OTHER-CAFE', 'pc_console_type' => 0, 'pc_in_using' => 1],
            ]]);
            return Http::response(['code' => 200, 'data' => []]);
        });
    }

    private function token(array $attrs = []): string
    {
        [, $token] = ApiClient::issue(array_merge(['name' => 'Панель собственника', 'club_id' => $this->club->id, 'scopes' => ['pcs', 'shifts']], $attrs));
        return $token;
    }

    private function api(string $token, string $path, string $ip = '2.29.219.88')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->withHeader('Authorization', 'Bearer '.$token)->get('/api/ext/v1'.$path);
    }

    public function test_auth_ip_scope_and_logging(): void
    {
        $this->get('/api/ext/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthorized');
        $this->api('ccx_wrong', '/me')->assertStatus(401);

        $token = $this->token(['allowed_ips' => '2.29.219.88,10.0.0.0/8']);
        $this->api($token, '/me')->assertOk()->assertJsonPath('club.name', 'SPOT')->assertJsonPath('club.timezone', 'Asia/Tashkent');
        $this->api($token, '/me', '10.1.2.3')->assertOk();
        $this->api($token, '/me', '8.8.8.8')->assertStatus(403)->assertJsonPath('error.code', 'ip_not_allowed');

        $onlyPcs = $this->token(['scopes' => ['pcs']]);
        $this->api($onlyPcs, '/shifts')->assertStatus(403)->assertJsonPath('error.code', 'scope_denied');

        $this->assertSame(4, DB::table('api_requests')->count());   // unknown tokens are not logged
        $this->assertSame(1, DB::table('api_requests')->where('status', 403)->where('path', '/api/ext/v1/me')->count());
        $c = ApiClient::whereNotNull('allowed_ips')->first();
        $this->assertSame('10.1.2.3', $c->last_ip);

        $c->revoked_at = now(); $c->save();
        $this->api($token, '/me')->assertStatus(401);
    }

    public function test_pcs_status_session_and_club_isolation(): void
    {
        $res = $this->api($this->token(), '/pcs')->assertOk();
        $this->assertSame(['PC001', 'PC002', 'PC003', 'PS5-1'], array_column($res->json('data'), 'name'));
        $pc2 = $res->json('data.1');
        $this->assertSame('busy', $pc2['status']);
        $this->assertSame('VIP', $pc2['zone']);
        $this->assertSame('ali', $pc2['session']['member_account']);
        $this->assertFalse($pc2['session']['guest']);
        $this->assertSame('2026-11-22T15:10:00+05:00', $pc2['session']['started_at']);
        $this->travelTo(CarbonImmutable::parse('2026-11-22 10:30:00'));   // 15:30 in Tashkent
        $pc2 = $this->api($this->token(), '/pcs')->json('data.1');
        $this->assertSame(100, $pc2['session']['minutes_left']);         // until 17:10
        $this->assertSame('free', $res->json('data.0.status'));
        $this->assertNull($res->json('data.0.session'));
        $this->assertSame('free', $res->json('data.2.status'));          // switched off but free
        $this->assertFalse($res->json('data.2.online'));
        $this->assertSame('console', $res->json('data.3.type'));
        $this->assertSame(['total' => 4, 'busy' => 1, 'free' => 3, 'disabled' => 0, 'online' => 2], array_intersect_key($res->json('meta'), array_flip(['total', 'busy', 'free', 'disabled', 'online'])));
    }

    public function test_empty_online_list_means_unknown_not_off(): void
    {
        $this->online = [];
        $res = $this->api($this->token(), '/pcs')->assertOk();
        $this->assertNull($res->json('data.0.online'));
        $this->assertNull($res->json('meta.online'));
        $this->assertSame('busy', $res->json('data.1.status'));
    }

    public function test_shifts_range_status_and_own_club_only(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 10:00:00'));
        $row = fn ($club, $id, $start, $end, $total) => DB::table('icafe_shifts')->insert(['club_id' => $club, 'shift_id' => $id, 'operator' => 'op',
            'started_at' => CarbonImmutable::parse($start, 'Asia/Tashkent')->utc(), 'ended_at' => $end ? CarbonImmutable::parse($end, 'Asia/Tashkent')->utc() : null,
            'total' => $total, 'cash' => $total, 'card' => 0, 'qr' => 0, 'synced_at' => now()]);
        $row($this->club->id, '101', '2026-11-21 08:00:00', '2026-11-21 20:00:00', 3200000);
        $row($this->club->id, '102', '2026-11-21 20:00:00', null, 450000);
        $row($this->club->id, '50', '2026-10-01 08:00:00', '2026-10-01 20:00:00', 1);
        $row($this->other->id, '900', '2026-11-21 08:00:00', '2026-11-21 20:00:00', 999);
        $token = $this->token();

        $res = $this->api($token, '/shifts')->assertOk();
        $this->assertSame(['101', '102'], array_column($res->json('data'), 'shift_id'));
        $this->assertSame('2026-11-21T08:00:00+05:00', $res->json('data.0.started_at'));
        $this->assertSame('closed', $res->json('data.0.status'));
        $this->assertEquals(3200000, $res->json('data.0.total'));
        $this->assertSame('open', $res->json('data.1.status'));
        $this->assertNull($res->json('data.1.ended_at'));

        $this->assertSame(['102'], array_column($this->api($token, '/shifts?status=open')->json('data'), 'shift_id'));
        $this->assertSame(['50'], array_column($this->api($token, '/shifts?from=2026-09-30&to=2026-10-02')->json('data'), 'shift_id'));
        $this->api($token, '/shifts?from=2026-01-01&to=2026-11-22')->assertStatus(422)->assertJsonPath('error.code', 'bad_range');
        $this->api($token, '/shifts?status=nope')->assertStatus(422)->assertJsonPath('error.code', 'bad_request');
    }

    public function test_rate_limit(): void
    {
        $token = $this->token();
        for ($i = 0; $i < 120; $i++) $this->api($token, '/me')->assertOk();
        $this->api($token, '/me')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited')->assertHeader('Retry-After');
    }

    public function test_owner_issues_and_revokes_tokens(): void
    {
        $lead = User::factory()->create(['role' => 'lead']);
        $this->actingAs($lead)->get('/api-access')->assertForbidden();
        $this->actingAs($lead)->post('/api-access', ['name' => 'x', 'club_id' => $this->club->id, 'scopes' => ['pcs']])->assertForbidden();

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->post('/api-access', ['name' => 'Сухроб', 'club_id' => $this->club->id, 'allowed_ips' => '2.29.219.88', 'scopes' => ['pcs', 'shifts']])
            ->assertSessionHasNoErrors();
        $token = session('newToken')['token'];
        $this->assertStringStartsWith('ccx_', $token);
        $client = ApiClient::firstOrFail();
        $this->assertNotSame($token, $client->token_hash);
        $this->assertSame(['2.29.219.88'], $client->ips());

        $this->actingAs($owner)->get('/api-access')->assertOk()->assertInertia(fn ($p) => $p->component('ApiAccess', false)
            ->has('clients', 1)->missing('clients.0.token_hash')->where('clients.0.hint', '…'.substr($token, -4)));
        $this->actingAs($owner)->post('/api-access', ['name' => 'x', 'club_id' => $this->club->id, 'allowed_ips' => '2.29.999.1', 'scopes' => ['pcs']])
            ->assertSessionHasErrors('allowed_ips');

        $this->api($token, '/me')->assertOk();
        $this->actingAs($owner)->post("/api-access/{$client->id}/revoke")->assertSessionHasNoErrors();
        $this->api($token, '/me')->assertStatus(401);
    }

    public function test_pcs_served_from_background_snapshot(): void
    {
        $token = $this->token();
        ApiClient::issue(['name' => 'без pcs', 'club_id' => $this->other->id, 'scopes' => ['shifts']]);
        app(\App\Domain\Icafe\PcSnapshot::class)->refreshActive();
        $this->assertNotNull(\Illuminate\Support\Facades\Cache::get('ext:pcs:'.$this->club->id));
        $this->assertNull(\Illuminate\Support\Facades\Cache::get('ext:pcs:'.$this->other->id));
        $calls = count(Http::recorded());
        $this->api($token, '/pcs')->assertOk()->assertJsonPath('meta.total', 4);
        $this->assertSame($calls, count(Http::recorded()), 'warm snapshot must not call iCafeCloud');
        // A stale snapshot is served at once and refreshed after the response.
        \Illuminate\Support\Facades\Cache::put('ext:pcs:'.$this->club->id, ['pcs' => [], 'online' => [], 'fetched_at' => now()->subMinute()->toIso8601String()], 120);
        $this->api($token, '/pcs')->assertOk()->assertJsonPath('meta.total', 0);
        $this->app->terminate();
        $this->api($token, '/pcs')->assertOk()->assertJsonPath('meta.total', 4);
        // Too old (or missing): fetched live.
        \Illuminate\Support\Facades\Cache::put('ext:pcs:'.$this->club->id, ['pcs' => [], 'online' => [], 'fetched_at' => now()->subMinutes(10)->toIso8601String()], 120);
        $this->api($token, '/pcs')->assertOk()->assertJsonPath('meta.total', 4);
        $this->assertSame('/api/ext/v1/pcs', DB::table('api_requests')->orderByDesc('id')->value('path'));
    }
}
