<?php

namespace Tests\Feature;

use App\Models\{Club, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationSecretTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_telegram_form_does_not_flash_bot_token(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $club = Club::create(['name' => 'Secret test', 'address' => 'Test']);
        $this->actingAs($owner)->from('/integrations')->post('/integrations/channels', [
            'club_id' => $club->id,
            'name' => 'Operations',
            'bot_token' => '123456:fictional-test-token',
            'chat_id' => 'invalid-chat',
            'kinds' => ['test'],
        ])->assertRedirect('/integrations')->assertSessionHasErrors('chat_id');

        $this->assertSame('Operations', session()->getOldInput('name'));
        $this->assertNull(session()->getOldInput('bot_token'));
        $this->assertDatabaseCount('telegram_channels', 0);
    }
}
