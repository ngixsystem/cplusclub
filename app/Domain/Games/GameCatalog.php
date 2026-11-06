<?php

namespace App\Domain\Games;

/**
 * Turns the process iCafeCloud Boot reports as running ("cs2.exe") into a game name.
 * Launchers, browsers, chat apps and the club shell are not games and are labelled separately.
 */
final class GameCatalog
{
    private const GAMES = [
        'cs2' => 'Counter-Strike 2', 'csgo' => 'CS:GO', 'dota2' => 'Dota 2', 'valorant-win64-shipping' => 'Valorant', 'valorant' => 'Valorant',
        'league of legends' => 'League of Legends', 'fortniteclient-win64-shipping' => 'Fortnite', 'tslgame' => 'PUBG: Battlegrounds',
        'r5apex' => 'Apex Legends', 'r5apex_dx12' => 'Apex Legends', 'gta5' => 'GTA V', 'gta5_enhanced' => 'GTA V', 'playgtav' => 'GTA V',
        'rdr2' => 'Red Dead Redemption 2', 'cod' => 'Call of Duty', 'modernwarfare' => 'Call of Duty', 'blackops6' => 'Call of Duty: Black Ops 6',
        'overwatch' => 'Overwatch 2', 'rocketleague' => 'Rocket League', 'fc24' => 'EA SPORTS FC 24', 'fc25' => 'EA SPORTS FC 25', 'fc26' => 'EA SPORTS FC 26',
        'efootball' => 'eFootball', 'robloxplayerbeta' => 'Roblox', 'javaw' => 'Minecraft', 'minecraft' => 'Minecraft', 'project8' => 'Deadlock',
        'marvel-win64-shipping' => 'Marvel Rivals', 'worldoftanks' => 'Мир танков', 'tanki' => 'Мир танков', 'worldofwarships64' => 'Мир кораблей',
        'war3' => 'Warcraft III', 'wow' => 'World of Warcraft', 'hearthstone' => 'Hearthstone', 'rainbowsix' => 'Rainbow Six Siege',
        'rainbowsix_vulkan' => 'Rainbow Six Siege', 'rustclient' => 'Rust', 'dayz_x64' => 'DayZ', 'escapefromtarkov' => 'Escape from Tarkov',
        'huntgame' => 'Hunt: Showdown', 'bf2042' => 'Battlefield 2042', 'bf6' => 'Battlefield 6', 'streetfighter6' => 'Street Fighter 6', 'mk12' => 'Mortal Kombat 1',
        'polaris-win64-shipping' => 'Tekken 8', 'narakabladepoint' => 'Naraka: Bladepoint', 'lostark' => 'Lost Ark', 'pathofexile_x64' => 'Path of Exile',
        'pathofexilesteam' => 'Path of Exile', 'pathofexile_x64steam' => 'Path of Exile', 'diablo iv' => 'Diablo IV', 'genshinimpact' => 'Genshin Impact',
        'zenlesszonezero' => 'Zenless Zone Zero', 'starrail' => 'Honkai: Star Rail', 'warthunder' => 'War Thunder', 'aces' => 'War Thunder',
        'deltaforce-win64-shipping' => 'Delta Force', 'thefinals' => 'The Finals', 'pubg' => 'PUBG: Battlegrounds', 'eldenring' => 'Elden Ring',
        'cyberpunk2077' => 'Cyberpunk 2077', 'forzahorizon5' => 'Forza Horizon 5', 'nba2k25' => 'NBA 2K25', 'mortalkombat11' => 'Mortal Kombat 11',
        'standoff2' => 'Standoff 2', 'warface' => 'Warface', 'crossfire' => 'CrossFire', 'stalker2-win64-shipping' => 'S.T.A.L.K.E.R. 2',
    ];

    /** Not games: shown in the live list, never ranked. */
    private const APPS = [
        'explorer' => 'Рабочий стол', 'icafemenu' => 'Меню клуба', 'ccbootclient' => 'Меню клуба', 'gizmoclient' => 'Меню клуба', 'smartlaunch' => 'Меню клуба',
        'steam' => 'Лаунчер', 'steamwebhelper' => 'Лаунчер', 'epicgameslauncher' => 'Лаунчер', 'battle.net' => 'Лаунчер', 'riotclientservices' => 'Лаунчер',
        'riotclientux' => 'Лаунчер', 'leagueclient' => 'Лаунчер', 'leagueclientux' => 'Лаунчер', 'eadesktop' => 'Лаунчер', 'ubisoftconnect' => 'Лаунчер',
        'upc' => 'Лаунчер', 'wargaming.net game center' => 'Лаунчер', 'lgc' => 'Лаунчер', 'faceit' => 'Лаунчер', 'faceitclient' => 'Лаунчер',
        'chrome' => 'Браузер', 'msedge' => 'Браузер', 'firefox' => 'Браузер', 'opera' => 'Браузер', 'browser' => 'Браузер', 'yandex' => 'Браузер',
        'discord' => 'Discord', 'telegram' => 'Telegram', 'obs64' => 'OBS', 'vlc' => 'Видео',
    ];

    /** @return array{kind:'game'|'app'|'none', name:?string} */
    public static function classify(?string $process): array
    {
        $key = self::key($process);
        if ($key === null) return ['kind' => 'none', 'name' => null];
        if (isset(self::APPS[$key])) return ['kind' => 'app', 'name' => self::APPS[$key]];
        if (isset(self::GAMES[$key])) return ['kind' => 'game', 'name' => self::GAMES[$key]];
        // Unknown executables on a club PC are almost always games: show the file name.
        return ['kind' => 'game', 'name' => mb_substr(preg_replace('/\.exe$/i', '', trim(basename(str_replace('\\', '/', (string) $process)))), 0, 80)];
    }

    /** "C:\Games\CS2\cs2.exe" → "cs2"; null for "-", empty and system noise. */
    public static function key(?string $process): ?string
    {
        $p = trim((string) $process);
        if ($p === '' || $p === '-' || $p === '0') return null;
        $file = mb_strtolower(basename(str_replace('\\', '/', $p)));
        $file = preg_replace('/\.exe$/', '', $file);
        if ($file === '' || in_array($file, ['system', 'idle', 'dwm', 'svchost', 'csrss', 'winlogon', 'lockapp', 'searchhost', 'shellexperiencehost'], true)) return null;
        return $file;
    }
}
