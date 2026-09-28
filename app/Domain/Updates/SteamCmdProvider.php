<?php
namespace App\Domain\Updates;
use Illuminate\Support\Facades\Http;
class SteamCmdProvider implements VersionProvider {
    public function fetch(int $appId):array {
        if(!in_array($appId,[730,570],true))throw new \InvalidArgumentException('Unsupported AppID');
        $response=Http::connectTimeout(5)->timeout(15)->get('https://api.steamcmd.net/v1/info/'.$appId);
        if(!$response->successful())throw new \RuntimeException('Version source unavailable');
        $branch=$response->json('data.'.$appId.'.depots.branches.public');
        if(!is_array($branch)||!isset($branch['buildid'])||!preg_match('/^\d{1,30}$/',(string)$branch['buildid']))throw new \RuntimeException('Invalid version source format');
        return ['build_id'=>(string)$branch['buildid'],'published_at'=>isset($branch['timeupdated'])&&ctype_digit((string)$branch['timeupdated'])?\Carbon\CarbonImmutable::createFromTimestampUTC((int)$branch['timeupdated']):null];
    }
}
