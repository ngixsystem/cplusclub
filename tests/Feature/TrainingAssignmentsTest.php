<?php

namespace Tests\Feature;

use App\Domain\Knowledge\TrainingAssignments;
use App\Domain\Telegram\UpdateHandler;
use App\Models\{Club, KnowledgeProgress, TelegramChannel, TelegramUserLink, TrainingAssignment, TrainingEnrollment, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{DB, Http};
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TrainingAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456:ABC_def';

    private array $sent = [];

    private bool $blocked = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['inertia.pages.paths' => [resource_path('js/Pages')], 'services.telegram.transport' => 'telegram', 'app.url' => 'https://admin.example.test']);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-25 06:00:00', 'UTC'));
        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            if (str_ends_with($r->url(), '/getMe')) {
                return Http::response(['ok' => true, 'result' => ['username' => 'cclub_bot']]);
            }
            if ($this->blocked) {
                return Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403);
            }
            $this->sent[] = ['chat' => (string) $r['chat_id'], 'text' => (string) $r['text']];

            return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
        });
        $club = Club::create(['name' => 'SPOT Gaming', 'address' => 'x']);
        $c = new TelegramChannel();
        $c->club_id = $club->id; $c->name = 'SPOT'; $c->bot_token = self::TOKEN; $c->chat_id = '-100500'; $c->kinds = ['ticket_intake'];
        $c->save();
    }

    private function privateMessage(string $text, int $chat = 4242, string $username = 'ivan'): void
    {
        app(UpdateHandler::class)->handle(self::TOKEN, ['update_id' => 9, 'message' => ['message_id' => 1, 'text' => $text,
            'from' => ['id' => $chat, 'first_name' => 'Иван', 'username' => $username, 'is_bot' => false], 'chat' => ['id' => $chat, 'type' => 'private']]]);
    }

    private function link(User $user, int $chat): void
    {
        $code = $this->actingAs($user)->postJson('/training/telegram/code')->assertOk()->assertJsonPath('bots.0', 'cclub_bot')->json('code');
        $this->privateMessage('/start '.$code, $chat);
    }

    public function test_employee_links_telegram_with_a_one_time_code(): void
    {
        $user = User::factory()->create(['role' => 'specialist', 'name' => 'Иван']);
        $this->actingAs($user)->get('/training')->assertInertia(fn (Assert $p) => $p->where('telegram.linked', false));
        $code = $this->postJson('/training/telegram/code')->assertOk()->json('code');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $code);

        $this->privateMessage('/start WRONG123');
        $this->assertSame(0, TelegramUserLink::count());
        $this->assertStringContainsString('Код не подошёл', end($this->sent)['text']);

        $this->privateMessage('/start '.strtolower($code));
        $link = TelegramUserLink::sole();
        $this->assertSame($user->id, $link->user_id);
        $this->assertSame('4242', $link->chat_id);
        $this->assertStringContainsString('Готово, Иван', end($this->sent)['text']);

        $this->privateMessage('/start '.$code, 5555); // a used code does not work twice
        $this->assertSame(1, TelegramUserLink::count());

        $this->actingAs($user)->get('/training')->assertInertia(fn (Assert $p) => $p->where('telegram.linked', true)->where('telegram.username', '@ivan'));
        $this->privateMessage('/stop');
        $this->assertSame(0, TelegramUserLink::count());

        // A group message with /start is not treated as a personal command and opens no ticket.
        app(UpdateHandler::class)->handle(self::TOKEN, ['update_id' => 10, 'message' => ['message_id' => 2, 'text' => '/start '.$code,
            'from' => ['first_name' => 'X', 'is_bot' => false], 'chat' => ['id' => -100500, 'type' => 'supergroup', 'title' => 'SPOT']]]);
        $this->assertSame(0, TelegramUserLink::count());
        $this->assertSame(0, DB::table('tickets')->count());
    }

    public function test_owner_and_lead_assign_programs_within_their_scope(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $lead = User::factory()->create(['role' => 'lead']);
        $engineer = User::factory()->create(['role' => 'specialist']);
        $this->link($engineer, 777);
        $this->sent = [];

        $this->actingAs($lead)->post('/training/assignments', ['user_id' => $engineer->id, 'track_id' => 'mikrotik', 'due_on' => '2026-12-10', 'note' => 'До открытия VIP'])
            ->assertSessionHasNoErrors();
        $a = TrainingAssignment::sole();
        $this->assertSame('2026-12-10', $a->due_on->toDateString());
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('«Cisco → MikroTik»', $this->sent[0]['text']);
        $this->assertStringContainsString('10.12.2026', $this->sent[0]['text']);

        $this->post('/training/assignments', ['user_id' => $engineer->id, 'track_id' => 'lead-server', 'due_on' => '2026-12-10'])->assertSessionHasErrors('track_id');
        $this->post('/training/assignments', ['user_id' => $engineer->id, 'track_id' => 'mikrotik', 'due_on' => '2026-11-20'])->assertSessionHasErrors('due_on');
        $this->post('/training/assignments', ['user_id' => $owner->id, 'track_id' => 'engineer-start', 'due_on' => '2026-12-10'])->assertForbidden();
        $this->post('/training/assignments', ['user_id' => $lead->id, 'track_id' => 'engineer-start', 'due_on' => '2026-12-10'])->assertForbidden();
        $this->actingAs($engineer)->post('/training/assignments', ['user_id' => $engineer->id, 'track_id' => 'mikrotik', 'due_on' => '2026-12-10'])->assertForbidden();

        $this->actingAs($owner)->post('/training/assignments', ['user_id' => $lead->id, 'track_id' => 'lead-server', 'due_on' => '2026-12-01'])->assertSessionHasNoErrors();
        $this->get('/training')->assertInertia(fn (Assert $p) => $p
            ->where('team', fn ($team) => collect($team)->firstWhere('id', $engineer->id)['assignments']['mikrotik']['days_left'] === 15
                && collect($team)->firstWhere('id', $lead->id)['assignments']['lead-server']['due_on'] === '2026-12-01'));
        $this->actingAs($engineer)->get('/training')->assertInertia(fn (Assert $p) => $p->where('assignments.mikrotik.note', 'До открытия VIP')->where('assignments.mikrotik.overdue', false));

        $this->actingAs($engineer)->delete("/training/assignments/{$a->id}")->assertForbidden();
        $this->actingAs($owner)->delete("/training/assignments/{$a->id}")->assertRedirect();
        $this->assertSame(1, TrainingAssignment::count());
    }

    public function test_daily_reminders_deadline_overdue_and_inactivity_are_sent_once(): void
    {
        $lead = User::factory()->create(['role' => 'lead', 'name' => 'Руководитель']);
        $engineer = User::factory()->create(['role' => 'specialist', 'name' => 'Иван']);
        $quiet = User::factory()->create(['role' => 'specialist']); // no Telegram — nothing is sent
        $this->link($lead, 100);
        $this->link($engineer, 200);
        $this->actingAs($lead)->post('/training/assignments', ['user_id' => $engineer->id, 'track_id' => 'mikrotik', 'due_on' => '2026-11-27'])->assertSessionHasNoErrors();
        $this->post('/training/assignments', ['user_id' => $quiet->id, 'track_id' => 'mikrotik', 'due_on' => '2026-11-26'])->assertSessionHasNoErrors();
        $this->sent = [];
        $reminders = app(TrainingAssignments::class);

        // 2 days before the deadline.
        $this->assertSame(1, $reminders->remind());
        $this->assertSame('200', $this->sent[0]['chat']);
        $this->assertStringContainsString('осталось дней: 2', $this->sent[0]['text']);
        $this->assertStringContainsString('https://admin.example.test/training', $this->sent[0]['text']);
        $this->assertSame(0, $reminders->remind(), 'the same reminder is not repeated');

        // Started but inactive for 3 days, then overdue: the employee and the lead are told.
        $this->actingAs($engineer)->post('/training/mikrotik/start');
        $this->travel(4)->days();
        $this->sent = [];
        $this->assertSame(3, $reminders->remind());
        $texts = collect($this->sent)->groupBy('chat')->map(fn ($m) => $m->pluck('text')->implode("\n"));
        $this->assertStringContainsString('прошёл 27.11.2026 (просрочка 2 дн.)', $texts['200']);
        $this->assertStringContainsString('не занимались обучением 4 дн.', $texts['200']);
        $this->assertStringContainsString('Иван не прошёл(а) программу «Cisco → MikroTik»', $texts['100']);
        $this->sent = [];
        $this->travel(1)->days();
        $this->assertSame(0, $reminders->remind());

        // Activity resets the inactivity clock; a completed program gets no more reminders.
        KnowledgeProgress::create(['user_id' => $engineer->id, 'article_id' => 'mt-from-cisco', 'checked' => [0]]);
        TrainingEnrollment::where('user_id', $engineer->id)->update(['completed_at' => now()]);
        $this->travel(10)->days();
        $this->assertSame(0, $reminders->remind());
        $this->assertSame([], $this->sent);
    }

    public function test_blocked_bot_drops_the_link(): void
    {
        $engineer = User::factory()->create(['role' => 'specialist']);
        $this->link($engineer, 300);
        $this->blocked = true;
        $this->assertFalse(app(\App\Domain\Telegram\PersonalLink::class)->send($engineer, 'x'));
        $this->assertSame(0, TelegramUserLink::count());
    }
}
