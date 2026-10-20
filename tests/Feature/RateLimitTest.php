<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
    }

    public function test_login_limit_counts_per_account_not_for_everyone(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post('/login', ['email' => 'wrong@example.test', 'password' => 'nope'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => 'wrong@example.test', 'password' => 'nope'])->assertStatus(429);

        // Another person behind the same club NAT is not locked out.
        $user = User::factory()->create(['password' => bcrypt('secret-pass'), 'active' => true]);
        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass'])->assertRedirect('/');
    }

    public function test_polling_other_endpoints_does_not_use_up_the_login_or_password_budget(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-pass'), 'active' => true]);
        // A minute of dashboard polling filled the old shared per-user counter
        // (key = sha1(user id)); the password limiter must not see those hits.
        foreach (range(1, 20) as $i) {
            RateLimiter::hit(sha1((string) $user->id), 60);
        }
        $this->actingAs($user)->post('/profile/password', [])->assertSessionHasErrors();
    }

    public function test_signed_in_user_is_sent_home_from_the_login_page(): void
    {
        $this->actingAs(User::factory()->create(['active' => true]))->get('/login')->assertRedirect('/');
    }

    public function test_client_ip_comes_from_the_trusted_proxy_header(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.9', 'HTTP_X_FORWARDED_FOR' => '195.158.30.134'])
            ->get('/health/live')->assertOk();
        $this->assertSame('195.158.30.134', request()->ip());
    }
}
