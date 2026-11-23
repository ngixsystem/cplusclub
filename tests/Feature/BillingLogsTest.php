<?php

namespace Tests\Feature;

use App\Domain\Icafe\BillingLogSync;
use App\Models\{ApiClient, Club, User};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

class BillingLogsTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    /** @var array<string, list<array>> local day => events, newest first */
    private array $days = [];
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-11-23 09:00:00'));       // 14:00 in Tashkent
        $this->club = Club::create(['name' => 'SPOT', 'address' => 'x']);
        $this->club->icafe_license = 88383; $this->club->icafe_token = 't'; $this->club->timezone = 'Asia/Tashkent'; $this->club->save();
        Http::fake(function ($req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            $this->requests[] = $q;
            $from = CarbonImmutable::parse($q['date_start'].' '.$q['time_start'], 'Asia/Tashkent');
            $to = CarbonImmutable::parse($q['date_end'].' '.$q['time_end'], 'Asia/Tashkent');
            $all = [];
            foreach ($this->days as $events) foreach ($events as $e) {
                $t = CarbonImmutable::parse($e['log_date_local'], 'Asia/Tashkent');
                if ($t->greaterThanOrEqualTo($from) && $t->lessThan($to->addMinute())) $all[] = $e;   // the end minute is inclusive
            }
            usort($all, fn ($a, $b) => $b['log_id'] <=> $a['log_id']);
            $page = (int) ($q['page'] ?? 1);
            return Http::response(['code' => 200, 'data' => ['log_list' => array_slice($all, ($page - 1) * 30, 30),
                'paging_info' => ['total_records' => count($all), 'pages' => (int) ceil(count($all) / 30), 'page' => (string) $page]]]);
        });
    }

    private function ev(int $id, string $local, string $event = 'TOPUP', array $extra = []): array
    {
        return array_merge(['log_id' => $id, 'log_icafe_id' => 88383, 'log_date' => CarbonImmutable::parse($local, 'Asia/Tashkent')->utc()->format('Y-m-d H:i:s'),
            'log_date_local' => $local, 'log_member_account' => 'ali', 'log_pc_name' => '007', 'log_event' => $event, 'log_money' => '10000.00',
            'log_spend' => '0.00', 'log_card' => '0.00', 'log_bonus' => '0.00', 'log_coin' => '0.00', 'log_used_secs' => '', 'log_details' => 'topup from cafe',
            'log_staff_name' => 'operator_01', 'pkg_name' => ''], $extra);
    }

    /** $n events on a local day, ids from $start, spread over the day. */
    private function day(string $date, int $start, int $n): void
    {
        for ($i = 0; $i < $n; $i++) $this->days[$date][] = $this->ev($start + $i, $date.' '.sprintf('%02d:%02d:00', intdiv($i * 7, 60) % 24, ($i * 7) % 60));
    }

    public function test_live_first_run_takes_the_last_hour_and_maps_fields(): void
    {
        $this->days['2026-11-23'] = [
            $this->ev(500, '2026-11-23 13:50:00', 'CHECKOUT', ['log_spend' => '500.00', 'log_money' => '0.00', 'log_used_secs' => '00:05:00']),
            $this->ev(499, '2026-11-23 13:20:00'),
            $this->ev(498, '2026-11-23 13:10:00', 'LOGIN', ['log_icafe_id' => 1]),   // another cafe on the same account
            $this->ev(100, '2026-11-23 09:00:00'),                                   // older than an hour: the backfill's job
        ];
        $this->assertSame(2, app(BillingLogSync::class)->live($this->club));
        $c = DB::table('icafe_billing_logs')->where('log_id', 500)->first();
        $this->assertSame('CHECKOUT', $c->event);
        $this->assertSame(300, (int) $c->used_seconds);
        $this->assertEquals(500, (float) $c->spend);
        $this->assertSame('2026-11-23 08:50:00', CarbonImmutable::parse($c->occurred_at)->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, BillingLogSync::seconds('00:00:01'));
        $this->assertSame(4272412, BillingLogSync::seconds('1186:46:52'));
    }

    public function test_live_stops_at_the_first_known_event(): void
    {
        $this->day('2026-11-23', 1000, 70);                       // ids 1000..1069 → 3 pages
        DB::table('icafe_billing_logs')->insert(['club_id' => $this->club->id, 'log_id' => 1050, 'occurred_at' => now(), 'event' => 'TOPUP', 'created_at' => now()]);
        $this->requests = [];
        $this->assertSame(29, app(BillingLogSync::class)->live($this->club));   // page 1: 1069..1040, 1050 already there
        $this->assertCount(1, $this->requests);                                 // page 1 reached a known id: no page 2
        $this->assertSame(1069, (int) DB::table('icafe_billing_logs')->max('log_id'));
    }

    public function test_backfill_walks_back_day_by_day_and_stops_after_empty_days(): void
    {
        $this->day('2026-11-23', 3000, 45);
        $this->day('2026-11-22', 2000, 700);                     // 24 pages: the first run stops inside this day
        $this->day('2026-11-20', 1000, 10);                       // 21st is empty: one gap day does not stop the walk
        $sync = app(BillingLogSync::class);
        $r = $sync->backfill($this->club);
        $this->assertFalse($r['done']);                           // 25 pages per run
        while (! ($r = $sync->backfill($this->club))['done']);
        $this->assertSame(755, DB::table('icafe_billing_logs')->count());
        $s = DB::table('icafe_billing_sync')->first();
        $this->assertSame('2026-11-20', $s->oldest_day);
        $this->assertNotNull($s->backfill_done_at);
        $this->assertSame(['stored' => 0, 'done' => true], $sync->backfill($this->club));
    }

    public function test_api_cursor_filters_scope_and_club_isolation(): void
    {
        $other = Club::create(['name' => 'TeamPro', 'address' => 'y']);
        $row = fn ($club, $logId, $event, $at) => DB::table('icafe_billing_logs')->insertGetId(['club_id' => $club, 'log_id' => $logId, 'event' => $event,
            'occurred_at' => CarbonImmutable::parse($at, 'Asia/Tashkent')->utc(), 'money' => 1000, 'member_account' => 'ali', 'created_at' => now()]);
        $a = $row($this->club->id, 10, 'TOPUP', '2026-11-22 10:00:00');
        $b = $row($this->club->id, 11, 'LOGIN', '2026-11-22 11:00:00');
        $row($other->id, 12, 'TOPUP', '2026-11-22 11:00:00');
        $c = $row($this->club->id, 5, 'CHECKOUT', '2026-11-01 09:00:00');      // older history loaded later: higher id
        DB::table('icafe_billing_sync')->insert(['club_id' => $this->club->id, 'oldest_day' => '2026-10-10', 'live_at' => now()]);

        [, $token] = ApiClient::issue(['name' => 'p', 'club_id' => $this->club->id, 'scopes' => ['billing']]);
        $get = fn ($q) => $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/ext/v1/billing-logs'.$q);

        $r = $get('?limit=2')->assertOk();
        $this->assertSame([$a, $b], array_column($r->json('data'), 'id'));
        $this->assertTrue($r->json('meta.has_more'));
        $this->assertSame($b, $r->json('meta.next_since'));
        $this->assertSame('2026-11-22T10:00:00+05:00', $r->json('data.0.occurred_at'));
        $this->assertSame('10', $r->json('data.0.log_id'));
        $this->assertSame('2026-10-10', $r->json('meta.sync.history_from'));

        $r = $get('?since='.$b)->assertOk();
        $this->assertSame([$c], array_column($r->json('data'), 'id'));
        $this->assertFalse($r->json('meta.has_more'));
        $this->assertSame($c, $get('?since='.$c)->json('meta.next_since'));   // nothing new: cursor stays

        $this->assertSame([$b], array_column($get('?event=login')->json('data'), 'id'));
        $this->assertSame([$c], array_column($get('?to=2026-11-02')->json('data'), 'id'));
        $get('?since=-1')->assertStatus(422)->assertJsonPath('error.code', 'bad_request');

        [, $noBilling] = ApiClient::issue(['name' => 'q', 'club_id' => $this->club->id, 'scopes' => ['pcs']]);
        $this->withHeader('Authorization', 'Bearer '.$noBilling)->getJson('/api/ext/v1/billing-logs')->assertStatus(403)->assertJsonPath('error.code', 'scope_denied');
    }

    public function test_owner_can_add_billing_to_an_existing_token(): void
    {
        [$client, $token] = ApiClient::issue(['name' => 'Сухроб', 'club_id' => $this->club->id, 'scopes' => ['pcs', 'shifts']]);
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/ext/v1/billing-logs')->assertStatus(403);
        $lead = User::factory()->create(['role' => 'lead']);
        $this->actingAs($lead)->post("/api-access/{$client->id}/scopes", ['scopes' => ['billing']])->assertForbidden();
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->post("/api-access/{$client->id}/scopes", ['scopes' => ['pcs', 'shifts', 'billing']])->assertSessionHasNoErrors();
        $this->assertSame(['pcs', 'shifts', 'billing'], $client->fresh()->scopes);
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/ext/v1/billing-logs')->assertOk();
        $this->actingAs($owner)->post("/api-access/{$client->id}/scopes", ['scopes' => ['nope']])->assertSessionHasErrors('scopes.0');
    }
}
