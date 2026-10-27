<?php

namespace Tests\Feature;

use App\Models\{Club, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LatencyTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = Club::create(['name' => 'SPOT', 'address' => 'x']);
        $this->token = Str::random(64);
        DB::table('agents')->insert(['id' => (string) Str::uuid(), 'club_id' => $this->club->id, 'name' => 'server', 'token_hash' => hash('sha256', $this->token), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function send(int $target, int $received, ?float $rtt): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->token)->postJson('/api/v1/latency', ['batch_id' => (string) Str::uuid(), 'observed_at' => now()->toIso8601String(),
            'results' => [['target_id' => $target, 'sent' => 5, 'received' => $received, 'rtt_avg_ms' => $rtt, 'rtt_max_ms' => $rtt]]]);
    }

    public function test_agent_gets_targets_reports_and_three_bad_samples_raise_one_alert(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->post('/monitoring/latency/targets', ['club_id' => $this->club->id, 'name' => 'CS2', 'host' => 'Game.Example.NET', 'max_rtt_ms' => 60, 'max_loss_pct' => 5])
            ->assertSessionHasNoErrors();
        $this->post('/monitoring/latency/targets', ['club_id' => $this->club->id, 'name' => 'bad', 'host' => '-c 1 evil', 'max_rtt_ms' => 60, 'max_loss_pct' => 5])->assertSessionHasErrors('host');
        $id = DB::table('latency_targets')->value('id');
        $this->assertSame('game.example.net', DB::table('latency_targets')->value('host'));

        $this->withToken($this->token)->getJson('/api/v1/latency/targets')->assertOk()->assertJsonPath('targets.0.id', $id);
        $this->withToken('wrong')->getJson('/api/v1/latency/targets')->assertUnauthorized();

        $this->send($id, 5, 20)->assertOk();
        $this->send($id, 5, 95)->assertOk();
        $this->send($id, 3, 30)->assertOk();
        $this->assertSame(0, DB::table('outbox_events')->where('kind', 'latency_problem')->count());
        $this->send($id, 0, null)->assertOk();
        $this->assertSame(1, DB::table('outbox_events')->where('kind', 'latency_problem')->count());
        $this->assertStringContainsString('сервер не отвечает', json_decode(DB::table('outbox_events')->where('kind', 'latency_problem')->value('payload'), true)['message']);
        $this->send($id, 0, null);
        foreach (range(1, 3) as $i) $this->send($id, 5, 20);
        $this->assertSame(1, DB::table('outbox_events')->where('kind', 'latency_recovered')->count());
        $this->assertSame(8, DB::table('latency_samples')->count());

        $this->withToken($this->token)->postJson('/api/v1/latency', ['batch_id' => (string) Str::uuid(), 'observed_at' => now()->toIso8601String(),
            'results' => [['target_id' => $id, 'sent' => 5, 'received' => 6, 'rtt_avg_ms' => 1, 'rtt_max_ms' => 1]]])->assertStatus(422);
    }

    public function test_other_clubs_target_is_rejected_and_page_and_csv_work(): void
    {
        $other = Club::create(['name' => 'Other', 'address' => 'y']);
        $foreign = DB::table('latency_targets')->insertGetId(['club_id' => $other->id, 'name' => 'X', 'host' => '1.1.1.1', 'created_at' => now(), 'updated_at' => now()]);
        $this->send($foreign, 5, 10)->assertForbidden();

        $mine = DB::table('latency_targets')->insertGetId(['club_id' => $this->club->id, 'name' => '=cmd', 'host' => '8.8.8.8', 'created_at' => now(), 'updated_at' => now()]);
        $this->send($mine, 5, 12)->assertOk();
        $this->withoutVite();
        $rep = User::factory()->create(['role' => 'representative']);
        $rep->clubs()->attach($this->club->id);
        $this->actingAs($rep)->get('/monitoring/latency')->assertOk()->assertInertia(fn ($p) => $p->component('Latency', false)->has('targets', 1)->where('canManage', false));
        $csv = $this->get('/monitoring/latency/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=cmd;8.8.8.8;1;12.0;12.0;0.00", $csv);
        $this->get('/monitoring/latency?club_id='.$other->id)->assertForbidden();
        $this->post('/monitoring/latency/targets/'.$mine, ['delete' => true])->assertForbidden();
    }
}
