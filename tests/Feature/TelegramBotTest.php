<?php

namespace Tests\Feature;

use App\Domain\Notifications\DispatchOutbox;
use App\Domain\Telegram\{BotUser, LinkFollowups, UpdateHandler};
use App\Domain\Tickets\{TicketStatus, TransitionTicket};
use App\Jobs\{DeliverTelegram, PollTelegram};
use App\Models\{Club, TelegramChannel, Ticket, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456:ABC_def';
    private const CHAT = '-1001112223334';

    private Club $club;
    private int $pc;
    private TelegramChannel $channel;
    private array $updates = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.transport' => 'telegram']);
        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => match (true) {
            str_ends_with($r->url(), '/getUpdates') => Http::response(['ok' => true, 'result' => $this->updates]),
            str_ends_with($r->url(), '/getMe') => Http::response(['ok' => true, 'result' => ['username' => 'spot_bot']]),
            default => Http::response(['ok' => true, 'result' => ['message_id' => 777]]),
        });
        $this->club = Club::create(['name' => 'SPOT Gaming', 'address' => 'x']);
        $this->pc = DB::table('equipment')->insertGetId(['club_id' => $this->club->id, 'name' => 'PC012', 'workstation_number' => '12',
            'zone' => 'зал 2', 'type' => 'pc', 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->channel = $this->channel(['link_down', 'link_restored', 'ticket_intake']);
    }

    private function channel(array $kinds, string $chat = self::CHAT): TelegramChannel
    {
        $c = new TelegramChannel();
        $c->club_id = $this->club->id; $c->name = 'SPOT'; $c->bot_token = self::TOKEN; $c->chat_id = $chat; $c->kinds = $kinds;
        $c->save();
        return $c;
    }

    private function followup(): int
    {
        $alert = DB::table('alerts')->insertGetId(['club_id' => $this->club->id, 'equipment_id' => $this->pc, 'metric' => 'link_speed', 'state' => 'active', 'episode' => 1]);
        return LinkFollowups::open($alert, 1, $this->club->id, $this->pc, 'PC012 · место 12 · зал 2', 100, 1000);
    }

    private function press(string $data, string $chat = self::CHAT): void
    {
        app(UpdateHandler::class)->handle(self::TOKEN, ['update_id' => 1, 'callback_query' => ['id' => 'cb1', 'data' => $data,
            'from' => ['first_name' => 'Азиз'], 'message' => ['message_id' => 50, 'text' => '⚠️ PC012', 'chat' => ['id' => (int) $chat, 'type' => 'supergroup', 'title' => 'SPOT']]]]);
    }

    private function say(string $text, array $extra = []): void
    {
        app(UpdateHandler::class)->handle(self::TOKEN, ['update_id' => 2, 'message' => ['message_id' => 60, 'text' => $text,
            'from' => ['first_name' => 'Дилноза', 'is_bot' => false], 'chat' => ['id' => (int) self::CHAT, 'type' => 'supergroup', 'title' => 'SPOT']] + $extra]);
    }

    public function test_link_down_message_carries_the_two_buttons(): void
    {
        $id = $this->followup();
        $payload = json_encode(['message' => 'x', 'buttons' => LinkFollowups::buttons($id)]);
        $this->assertSame([[['✅ Переподключил', 'lf:'.$id.':ok'], ['❌ Не помогло', 'lf:'.$id.':fail']]], DeliverTelegram::buttons($payload));
        $this->assertSame([], DeliverTelegram::buttons('{"buttons":"nope"}'));
    }

    public function test_not_helped_opens_one_high_network_ticket_and_edits_the_message(): void
    {
        $id = $this->followup();
        $this->press('lf:'.$id.':fail');
        $ticket = Ticket::sole();
        $this->assertSame(['Сеть', 'high', 'link', $this->pc, $this->channel->id, BotUser::id()],
            [$ticket->category, $ticket->priority, $ticket->source, $ticket->equipment_id, $ticket->source_channel_id, $ticket->initiator_id]);
        $this->assertStringContainsString('не помогло', $ticket->description);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText') && str_contains($r['text'], 'Создана заявка #'.$ticket->id) && ! isset($r['reply_markup']));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery') && $r['callback_query_id'] === 'cb1');

        $this->press('lf:'.$id.':fail');
        $this->assertSame(1, Ticket::count());
        $this->assertSame('ticketed', DB::table('link_followups')->value('state'));
    }

    public function test_reconnected_but_still_slow_after_ten_minutes_opens_a_ticket(): void
    {
        $id = $this->followup();
        $this->press('lf:'.$id.':ok');
        $this->assertSame('reconnected', DB::table('link_followups')->value('state'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText') && str_contains($r['text'], 'Азиз переподключил')
            && count($r['reply_markup']['inline_keyboard'][0]) === 1);

        $this->assertSame(0, app(LinkFollowups::class)->sweep());
        $this->travel(11)->minutes();
        DB::table('equipment')->where('id', $this->pc)->update(['last_seen_at' => now()]);
        $this->assertSame(1, app(LinkFollowups::class)->sweep());
        $this->assertSame(0, app(LinkFollowups::class)->sweep());
        $ticket = Ticket::sole();
        $this->assertSame('Азиз', $ticket->reporter_name);

        // The group is told, through its link_down subscription.
        app(DispatchOutbox::class)->execute();
        $event = DB::table('outbox_events')->where('kind', 'link_ticket')->sole();
        $this->assertSame(1, DB::table('deliveries')->where('outbox_event_id', $event->id)->where('telegram_channel_id', $this->channel->id)->count());
        $this->assertStringContainsString('создали заявку #'.$ticket->id, DeliverTelegram::text('link_ticket', $event->id, $event->payload));
    }

    public function test_recovered_link_or_switched_off_pc_never_opens_a_ticket(): void
    {
        $this->followup();
        $this->travel(61)->minutes();
        DB::table('equipment')->where('id', $this->pc)->update(['last_seen_at' => now()->subMinutes(30)]);
        $this->assertSame(0, app(LinkFollowups::class)->sweep(), 'PC is off: wait');

        DB::table('equipment')->where('id', $this->pc)->update(['last_seen_at' => now()]);
        DB::table('alerts')->update(['state' => 'normal']);
        $this->assertSame(0, app(LinkFollowups::class)->sweep());
        $this->assertSame('recovered', DB::table('link_followups')->value('state'));
        $this->assertSame(0, Ticket::count());
    }

    public function test_silence_for_an_hour_opens_a_ticket_only_where_a_group_was_asked(): void
    {
        $this->followup();
        $this->channel->kinds = ['ticket_intake']; $this->channel->save();
        $this->travel(61)->minutes();
        DB::table('equipment')->where('id', $this->pc)->update(['last_seen_at' => now()]);
        $this->assertSame(0, app(LinkFollowups::class)->sweep());

        $this->channel->kinds = ['link_down']; $this->channel->save();
        $this->assertSame(1, app(LinkFollowups::class)->sweep());
        $this->assertStringContainsString('никто не отметил', Ticket::sole()->description);
    }

    public function test_buttons_from_another_club_group_are_refused(): void
    {
        $id = $this->followup();
        $other = Club::create(['name' => 'Other', 'address' => 'y']);
        $c = new TelegramChannel();
        $c->club_id = $other->id; $c->name = 'Other'; $c->bot_token = self::TOKEN; $c->chat_id = '-1009999'; $c->kinds = ['link_down']; $c->save();
        $this->press('lf:'.$id.':fail', '-1009999');
        $this->assertSame(0, Ticket::count());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery') && str_contains($r['text'], 'не относится'));
    }

    public function test_ticket_from_the_group_with_pc_number_and_a_reply_comment(): void
    {
        $this->say('/ticket ПК 12 не запускается игра');
        $ticket = Ticket::sole();
        $this->assertSame([$this->pc, 'Программы и игры', 'telegram', 'Дилноза', '777', 'ПК 12 не запускается игра'],
            [$ticket->equipment_id, $ticket->category, $ticket->source, $ticket->reporter_name, $ticket->bot_message_id, $ticket->description]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], 'Заявка #'.$ticket->id.' принята · PC012')
            && $r['reply_parameters']['message_id'] === 60);

        $this->say('кабель тоже проверили', ['reply_to_message' => ['message_id' => 777, 'from' => ['is_bot' => true]]]);
        $this->assertSame(1, Ticket::count());
        $this->assertTrue($ticket->events()->where('comment', 'like', '%кабель тоже проверили%')->exists());

        $this->say('заявка: нет интернета на всех пк');
        $this->assertSame(['Сеть', 'high'], [Ticket::latest('id')->first()->category, Ticket::latest('id')->first()->priority]);
        $this->say('просто разговор про заявку');
        $this->say('/ticket');
        $this->assertSame(2, Ticket::count());
    }

    public function test_groups_without_intake_are_ignored(): void
    {
        $this->channel->kinds = ['link_down']; $this->channel->save();
        $this->say('/ticket ПК 12 не работает');
        $this->assertSame(0, Ticket::count());
        $this->assertSame(1, DB::table('telegram_seen_chats')->where('chat_id', self::CHAT)->count());
    }

    public function test_status_goes_back_only_to_the_source_group_and_rating_is_saved(): void
    {
        $team = $this->channel(['monitor_problem', 'ticket_status', 'ticket_rated', 'test'], '-1005555');
        $this->say('/ticket ПК 12 гудит');
        $ticket = Ticket::sole();
        $lead = User::factory()->create(['role' => 'lead']);
        $ticket = app(TransitionTicket::class)->execute($lead, $ticket->id, TicketStatus::Accepted, $ticket->version, []);
        app(DispatchOutbox::class)->execute();
        $status = DB::table('outbox_events')->where('kind', 'ticket_status')->sole();
        $this->assertSame([$this->channel->id], DB::table('deliveries')->where('outbox_event_id', $status->id)->pluck('telegram_channel_id')->all());
        $this->assertStringContainsString('принята специалистом', DeliverTelegram::text('ticket_status', $status->id, $status->payload));

        $ticket = app(TransitionTicket::class)->execute($lead, $ticket->id, TicketStatus::Working, $ticket->version, []);
        $ticket = app(TransitionTicket::class)->execute($lead, $ticket->id, TicketStatus::Resolved, $ticket->version,
            ['work_result' => 'Заменён кулер', 'verification_result' => 'Тихо', 'cause' => 'Износ', 'solution' => 'Замена кулера']);
        app(TransitionTicket::class)->execute($lead, $ticket->id, TicketStatus::Closed, $ticket->version, []);
        $closed = DB::table('outbox_events')->where('kind', 'ticket_status')->orderByDesc('id')->first();
        $this->assertCount(5, DeliverTelegram::buttons($closed->payload)[0]);

        $this->press('rt:'.$ticket->id.':4');
        $this->assertSame([4, 'Азиз'], [$ticket->fresh()->rating, $ticket->fresh()->rated_by]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText') && str_contains($r['text'], '★★★★☆'));
        app(DispatchOutbox::class)->execute();
        $rated = DB::table('outbox_events')->where('kind', 'ticket_rated')->sole();
        $this->assertSame([$team->id], DB::table('deliveries')->where('outbox_event_id', $rated->id)->pluck('telegram_channel_id')->all());
    }

    public function test_poller_saves_offset_and_bot_user_is_hidden(): void
    {
        $this->updates = [['update_id' => 41, 'my_chat_member' => ['chat' => ['id' => -100777, 'title' => 'Новая группа', 'type' => 'group']]]];
        (new PollTelegram(0))->handle(app(\App\Domain\Telegram\TelegramApi::class), app(UpdateHandler::class));
        $this->assertSame(42, (int) DB::table('telegram_bot_state')->value('update_offset'));
        $this->assertSame('Новая группа', DB::table('telegram_seen_chats')->where('chat_id', '-100777')->value('title'));

        // «Найти группу» for a bot that is already polled reads the recorded chats.
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->postJson('/integrations/telegram/chats', ['bot_token' => self::TOKEN])->assertOk()->assertJsonPath('chats.0.title', 'Новая группа');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'getUpdates') && $r->method() === 'GET');

        BotUser::id();
        $this->withoutVite();
        $this->get('/users')->assertOk()->assertDontSee(BotUser::EMAIL);
    }
}
