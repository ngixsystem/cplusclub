<?php

namespace Tests\Feature;

use App\Domain\Remote\RustDesk;
use App\Models\{Club, RemoteHost, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RemoteAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_owner_adds_computer_installer_enrolls_and_operator_connects(): void
    {
        $club = Club::create(['name' => 'SPOT', 'address' => 'x']);
        $owner = User::factory()->create(['role' => 'owner']);

        $res = $this->actingAs($owner)->post('/remote/hosts', ['club_id' => $club->id, 'name' => 'Сервер iCafe'])->assertSessionHasNoErrors();
        $cmd = session('installCommand');
        $this->assertNotNull($cmd);
        preg_match("/-Token '([A-Za-z0-9]{40})'/", $cmd, $m);
        $token = $m[1];
        $host = RemoteHost::firstOrFail();
        $this->assertNull($host->rustdesk_id);
        $this->assertNotSame($host->getRawOriginal('password'), $host->password, 'password is stored encrypted');

        $script = $this->get('/rustdesk/install.ps1')->assertOk()->getContent();
        $this->assertStringStartsWith('#', $script, 'no BOM: irm keeps it and breaks param()');
        $this->assertStringContainsString(RustDesk::configString(), $script);
        $this->assertStringNotContainsString('__CONFIG__', $script);
        $this->assertStringNotContainsString($host->password, $script);

        $this->postJson('/api/v1/remote/enroll', ['token' => str_repeat('x', 40), 'id' => '123456789'])->assertForbidden();
        $this->postJson('/api/v1/remote/enroll', ['token' => $token, 'id' => '123456789', 'hostname' => 'SRV-SPOT'])
            ->assertOk()->assertJson(['password' => $host->password]);
        $this->postJson('/api/v1/remote/enroll', ['token' => $token, 'id' => '987654321'])->assertForbidden();
        $host->refresh();
        $this->assertSame('123456789', $host->rustdesk_id);
        $this->assertNull($host->enroll_token_hash);

        $spec = User::factory()->create(['role' => 'specialist']);
        $this->actingAs($spec)->postJson("/remote/hosts/{$host->id}/connect")->assertForbidden();
        $spec->clubs()->attach($club->id);
        $url = $this->actingAs($spec)->postJson("/remote/hosts/{$host->id}/connect")->assertOk()->json('url');
        $this->assertStringStartsWith('rustdesk://connection/new/123456789@'.RustDesk::host().'?key=', $url);
        $this->assertStringContainsString('password='.rawurlencode($host->password), $url);
        $this->assertSame(1, DB::table('remote_connections')->where('user_id', $spec->id)->count());

        $this->actingAs($spec)->get('/remote')->assertOk()->assertInertia(fn ($p) => $p->component('Remote', false)
            ->has('hosts', 1)->where('hosts.0.rustdesk_id', '123 456 789')->where('canManage', false)->missing('hosts.0.password'));
        $this->actingAs($spec)->postJson("/remote/hosts/{$host->id}/password")->assertForbidden();
        $this->actingAs($spec)->post('/remote/hosts', ['club_id' => $club->id, 'name' => 'x'])->assertForbidden();

        $rep = User::factory()->create(['role' => 'representative']);
        $rep->clubs()->attach($club->id);
        $this->actingAs($rep)->get('/remote')->assertForbidden();
        $this->actingAs($rep)->postJson("/remote/hosts/{$host->id}/connect")->assertForbidden();
    }

    public function test_manual_id_and_pending_host_cannot_connect(): void
    {
        $club = Club::create(['name' => 'A', 'address' => 'x']);
        $lead = User::factory()->create(['role' => 'lead']);
        $this->actingAs($lead)->post('/remote/hosts', ['club_id' => $club->id, 'name' => 'ПК 1', 'rustdesk_id' => '1 234 567 89'])->assertSessionHasNoErrors();
        $this->assertSame('123456789', RemoteHost::value('rustdesk_id'));
        $this->post('/remote/hosts', ['club_id' => $club->id, 'name' => 'ПК 2']);
        $pending = RemoteHost::where('name', 'ПК 2')->first();
        $this->postJson("/remote/hosts/{$pending->id}/connect")->assertStatus(409);
        $this->post("/remote/hosts/{$pending->id}", ['delete' => true]);
        $this->assertSame(1, RemoteHost::count());
    }

    public function test_config_string_decodes_to_server_and_key(): void
    {
        $s = strrev(RustDesk::configString());
        $json = json_decode(base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4)), true);
        $this->assertSame(RustDesk::host(), $json['host']);
        $this->assertSame(RustDesk::key(), $json['key']);
    }

    public function test_presence_keeps_state_when_rustdesk_server_is_unreachable(): void
    {
        config(['services.rustdesk.status_host' => '127.0.0.1', 'services.rustdesk.port' => 9]); // nothing listens on :8
        $club = Club::create(['name' => 'B', 'address' => 'x']);
        $h = new RemoteHost();
        $h->club_id = $club->id; $h->name = 'srv'; $h->password = 'p'; $h->rustdesk_id = '123456789'; $h->online = true;
        $h->save();
        $this->assertNull(\App\Domain\Remote\Presence::refresh());
        $this->assertTrue($h->fresh()->online);
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->getJson('/remote/presence')->assertOk()->assertJsonPath('hosts.0.online', true);
        $this->get('/remote')->assertInertia(fn ($p) => $p->component('Remote', false)->where('hosts.0.online', true));
    }
}
