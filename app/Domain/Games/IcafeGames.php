<?php

namespace App\Domain\Games;

use App\Domain\Icafe\Client;
use App\Models\Club;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Cache, DB};

/**
 * The club's game list from iCafeCloud («games»). Each game carries an all-time counter of
 * launches through the club menu; the hourly sync stores the growth since the previous sync
 * as launches of that day. The exe files also name games the built-in catalog does not know.
 */
final class IcafeGames
{
    /** Classes that are tools and launchers, not games. */
    private const NOT_GAMES = '/launcher|internet|apps|tools|utilit|прилож|утилит|браузер|system/iu';

    public function __construct(private Client $client) {}

    /** @return array{games:int,launches:int} */
    public function sync(Club $club): array
    {
        if (! $club->icafe_license || ! $club->icafe_token) return ['games' => 0, 'launches' => 0];
        $data = $this->client->get($club->icafe_license, $club->icafe_token, 'games');
        $rows = array_filter((array) ($data['games'] ?? []), fn ($g) => isset($g['pkg_id'], $g['pkg_name']));
        $tz = $club->timezone ?: 'Asia/Tashkent';
        $today = CarbonImmutable::now($tz)->toDateString();
        $added = 0;
        DB::transaction(function () use ($club, $rows, $today, &$added) {
            $old = DB::table('icafe_games')->where('club_id', $club->id)->lockForUpdate()->pluck('launches', 'pkg_id');
            foreach ($rows as $g) {
                $id = (int) $g['pkg_id'];
                $hot = max(0, (int) ($g['pkg_local_hot'] ?? 0));
                $name = mb_substr(trim((string) $g['pkg_name']), 0, 120);
                $files = trim(implode(';', array_filter([(string) ($g['pkg_match_files'] ?? ''), basename(str_replace('\\', '/', (string) ($g['pkg_run_exe'] ?? '')))])), ';');
                DB::table('icafe_games')->upsert([['club_id' => $club->id, 'pkg_id' => $id, 'name' => $name, 'class' => mb_substr((string) ($g['pkg_idc_class'] ?? ''), 0, 60),
                    'exe_files' => mb_substr($files, 0, 2000), 'launches' => $hot, 'synced_at' => now()]], ['club_id', 'pkg_id'], ['name', 'class', 'exe_files', 'launches', 'synced_at']);
                // The first sync is the baseline; a counter that went down was reset and is not counted.
                $delta = isset($old[$id]) ? $hot - (int) $old[$id] : 0;
                if ($delta > 0 && $delta < 100000) {
                    DB::statement('INSERT INTO icafe_game_launches_daily (club_id, day, pkg_id, name, launches) VALUES (?, ?, ?, ?, ?)
                        ON CONFLICT (club_id, day, pkg_id) DO UPDATE SET launches = icafe_game_launches_daily.launches + EXCLUDED.launches, name = EXCLUDED.name',
                        [$club->id, $today, $id, $name, $delta]);
                    $added += $delta;
                }
            }
        });
        Cache::forget('icafe-games:exe:'.$club->id);
        return ['games' => count($rows), 'launches' => $added];
    }

    /** exe file name (lower case, no .exe) => game name from the club's iCafe list. */
    public static function exeMap(int $clubId): array
    {
        return Cache::remember('icafe-games:exe:'.$clubId, now()->addHour(), function () use ($clubId) {
            $map = [];
            foreach (DB::table('icafe_games')->where('club_id', $clubId)->whereNotNull('exe_files')->get(['name', 'class', 'exe_files']) as $g) {
                if (preg_match(self::NOT_GAMES, (string) $g->class)) continue;
                foreach (explode(';', (string) $g->exe_files) as $f) {
                    $key = GameCatalog::key(trim($f));
                    // Folder patterns such as "/steamapps/" are not exe names.
                    if ($key !== null && ! preg_match('~[/\\\\]~', trim($f))) $map[$key] ??= $g->name;
                }
            }
            return $map;
        });
    }

    public static function isGame(?string $class): bool
    {
        return ! preg_match(self::NOT_GAMES, (string) $class);
    }
}
