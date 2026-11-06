<?php

namespace Tests\Feature;

use App\Domain\Games\{GameCatalog, GameUsage, IcafeGames};
use App\Models\{Club, Equipment, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

class GamesTest extends TestCase
{
    use RefreshDatabase;

    private function club(string $name = 'SPOT'): Club
    {
        $c = Club::create(['name' => $name, 'address' => 'x']);
        $c->icafe_license = random_int(1000, 999999); $c->icafe_token = 't'; $c->timezone = 'Asia/Tashkent'; $c->save();
        return $c;
    }

    private function sample(int $pc, ?string $process, $at = null): void
    {
        DB::table('telemetry_samples')->insert(['equipment_id' => $pc, 'observed_at' => $at ?? now(), 'received_at' => now(), 'active_process' => $process,
            'sensor_status' => '{}']);
    }

    public function test_catalog_names_games_and_skips_launchers(): void
    {
        $this->assertSame(['kind' => 'game', 'name' => 'Counter-Strike 2'], GameCatalog::classify('C:\\Games\\CS2\\cs2.exe'));
        $this->assertSame(['kind' => 'game', 'name' => 'Dota 2'], GameCatalog::classify('dota2.exe'));
        $this->assertSame(['kind' => 'app', 'name' => 'Лаунчер'], GameCatalog::classify('steam.exe'));
        $this->assertSame(['kind' => 'none', 'name' => null], GameCatalog::classify('-'));
        $this->assertSame(['kind' => 'none', 'name' => null], GameCatalog::classify('NA'));
        $this->assertSame(['kind' => 'game', 'name' => 'HogwartsLegacy'], GameCatalog::classify('HogwartsLegacy.exe'));
    }

    public function test_minutes_are_counted_once_per_minute_and_ranked(): void
    {
        $c = $this->club();
        $pcs = [['activeprocess' => 'cs2.exe'], ['activeprocess' => 'cs2.exe'], ['activeprocess' => 'dota2.exe'], ['activeprocess' => 'steam.exe'], ['activeprocess' => '-']];
        $this->travelTo(now('Asia/Tashkent')->setTime(15, 10));
        GameUsage::record($c->id, $pcs, now());
        GameUsage::record($c->id, $pcs, now());                       // same minute polled twice
        $this->travel(1)->minutes(); GameUsage::record($c->id, $pcs, now());
        $this->assertSame(4, (int) DB::table('game_usage_hourly')->where('game', 'Counter-Strike 2')->value('minutes'));
        $this->assertSame(0, DB::table('game_usage_hourly')->where('game', 'Лаунчер')->count());

        $s = app(GameUsage::class)->stats($c, 'today', 'days');
        $this->assertSame(['Counter-Strike 2', 'Dota 2'], collect($s['top'])->pluck('name')->all());
        $this->assertSame(67, $s['top'][0]['share']);
        $this->assertCount(14, $s['trend']['labels']);
        $this->assertSame('Counter-Strike 2', collect($s['trend']['leaders'])->last()['game']);
        $this->assertSame([], app(GameUsage::class)->stats($c, 'yesterday', 'weeks')['top']);
        $this->assertCount(8, app(GameUsage::class)->stats($c, 'week', 'weeks')['trend']['labels']);
    }

    public function test_live_groups_pcs_by_game_and_access_is_per_club(): void
    {
        $c = $this->club();
        $other = $this->club('Other');
        $a = Equipment::create(['club_id' => $c->id, 'name' => 'PC002', 'type' => 'pc']);
        $b = Equipment::create(['club_id' => $c->id, 'name' => 'PC001', 'type' => 'pc']);
        $d = Equipment::create(['club_id' => $c->id, 'name' => 'PC003', 'type' => 'pc']);
        Equipment::create(['club_id' => $c->id, 'name' => 'PC004', 'type' => 'pc']);
        $this->sample($a->id, 'cs2.exe'); $this->sample($b->id, 'cs2.exe'); $this->sample($d->id, 'chrome.exe');
        $this->sample($d->id, 'dota2.exe', now()->subMinutes(10));    // stale reading is ignored

        $this->withoutVite();
        $rep = User::factory()->create(['role' => 'representative']);
        $rep->clubs()->attach($c->id);
        $this->actingAs($rep)->getJson('/games/live?club_id='.$c->id)->assertOk()
            ->assertJsonPath('playing', 2)->assertJsonPath('on', 3)->assertJsonPath('total', 4)->assertJsonPath('apps', 1)
            ->assertJsonPath('games.0.name', 'Counter-Strike 2')->assertJsonPath('games.0.pcs.0.name', 'PC001');
        $this->getJson('/games/live?club_id='.$other->id)->assertForbidden();
        $this->getJson('/games/stats?club_id='.$c->id.'&period=month&trend=weeks')->assertOk()->assertJsonPath('trend.mode', 'weeks');
        $this->getJson('/games/stats?club_id='.$c->id.'&period=forever')->assertStatus(422);
        $this->get('/')->assertOk()->assertInertia(fn ($p) => $p->component('Overview', false)->where('gameClubs', [['id' => $c->id, 'name' => 'SPOT']]));
    }

    public function test_icafe_launch_counters_become_daily_launches_and_name_unknown_exes(): void
    {
        $c = $this->club();
        $games = [['pkg_id' => 1, 'pkg_name' => 'Counter-Strike 2', 'pkg_idc_class' => 'Online Games', 'pkg_local_hot' => 500, 'pkg_match_files' => 'cs2.exe', 'pkg_run_exe' => ''],
            ['pkg_id' => 2, 'pkg_name' => 'Steam', 'pkg_idc_class' => 'Launcher Games', 'pkg_local_hot' => 9000, 'pkg_match_files' => 'Steam.exe;/steamapps/', 'pkg_run_exe' => ''],
            ['pkg_id' => 3, 'pkg_name' => 'ICCup Launcher', 'pkg_idc_class' => 'Online Games', 'pkg_local_hot' => 300, 'pkg_match_files' => '', 'pkg_run_exe' => 'D:\\Games\\iccup\\iCCupLauncher.exe']];
        Http::preventStrayRequests();
        Http::fake(function () use (&$games) { return Http::response(['code' => 200, 'data' => ['games' => $games, 'game_types' => []]]); });
        $sync = app(IcafeGames::class);
        $this->assertSame(['games' => 3, 'launches' => 0], $sync->sync($c->fresh()), 'first sync is the baseline');
        $games[0]['pkg_local_hot'] = 512; $games[2]['pkg_local_hot'] = 299;   // a counter going down is a reset, not launches
        $this->assertSame(12, $sync->sync($c->fresh())['launches']);

        $s = app(GameUsage::class)->stats($c, 'today', 'days', 'launches');
        $this->assertSame([['name' => 'Counter-Strike 2', 'launches' => 12, 'hours' => 0, 'share' => 100]], $s['top']);
        $all = app(GameUsage::class)->stats($c, 'all', 'days', 'launches');
        $this->assertSame(['Counter-Strike 2', 'ICCup Launcher'], collect($all['top'])->pluck('name')->all(), 'launchers are not ranked');

        $this->assertSame(['kind' => 'game', 'name' => 'ICCup Launcher'], GameCatalog::classify('iCCupLauncher.exe', IcafeGames::exeMap($c->id)));
        $this->assertSame('app', GameCatalog::classify('steam.exe', IcafeGames::exeMap($c->id))['kind']);
        $this->assertArrayNotHasKey('steamapps', IcafeGames::exeMap($c->id));
    }
}
