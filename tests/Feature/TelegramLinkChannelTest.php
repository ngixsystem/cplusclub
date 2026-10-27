<?php

namespace Tests\Feature;

use App\Models\{Club, TelegramChannel, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

class TelegramLinkChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_find_group_lists_chats_and_never_keeps_the_token(): void
    {
        config(['services.telegram.transport' => 'http']);
        Http::preventStrayRequests();
        Http::fake([
            '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'spot_link_bot']]),
            '*/getUpdates*' => Http::response(['ok' => true, 'result' => [
                ['my_chat_member' => ['chat' => ['id' => -1001112223334, 'title' => 'SPOT админы', 'type' => 'supergroup']]],
                ['message' => ['chat' => ['id' => -1001112223334, 'title' => 'SPOT админы', 'type' => 'supergroup']]],
                ['message' => ['chat' => ['id' => 55, 'first_name' => 'Личка', 'type' => 'private']]],
            ]]),
        ]);
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->postJson('/integrations/telegram/chats', ['bot_token' => '123456:ABC_def-GHI'])
            ->assertOk()->assertJsonPath('bot', 'spot_link_bot')
            ->assertJsonCount(1, 'chats')->assertJsonPath('chats.0.id', '-1001112223334')->assertJsonPath('chats.0.title', 'SPOT админы');
        $this->assertSame(0, TelegramChannel::count());
        $this->assertSame(0, DB::table('audit_logs')->where('subject', 'like', '%ABC_def%')->count());

        Http::fake(['*/getMe' => Http::response(['ok' => false], 401)]);
        $this->postJson('/integrations/telegram/chats', ['bot_token' => '123456:wrong'])->assertStatus(422)->assertJsonPath('ok', false);
        $this->postJson('/integrations/telegram/chats', ['bot_token' => 'not a token'])->assertUnprocessable();

        $rep = User::factory()->create(['role' => 'representative']);
        $this->actingAs($rep)->postJson('/integrations/telegram/chats', ['bot_token' => '123456:ABC'])->assertForbidden();
    }

    public function test_client_group_can_subscribe_to_link_events_only(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $club = Club::create(['name' => 'SPOT Gaming', 'address' => 'x']);
        $this->actingAs($owner)->post('/integrations/channels', ['club_id' => $club->id, 'name' => 'SPOT админы', 'bot_token' => '123456:ABC_def', 'chat_id' => '-1001112223334', 'kinds' => ['link_down', 'link_restored', 'test']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $channel = TelegramChannel::sole();
        $this->assertSame(['link_down', 'link_restored', 'test'], $channel->kinds);
        $this->assertNotSame('123456:ABC_def', $channel->getRawOriginal('bot_token'));

        foreach (['monitor_problem' => 'temp', 'link_down' => 'link'] as $kind => $key) {
            DB::table('outbox_events')->insert(['club_id' => $club->id, 'kind' => $kind, 'dedup_key' => $key, 'payload' => '{"message":"x"}', 'created_at' => now()]);
        }
        app(\App\Domain\Notifications\DispatchOutbox::class)->execute();
        $kinds = DB::table('deliveries')->join('outbox_events', 'outbox_events.id', '=', 'deliveries.outbox_event_id')->pluck('kind')->all();
        $this->assertSame(['link_down'], $kinds, 'the client group gets no technical alerts');
    }
}
