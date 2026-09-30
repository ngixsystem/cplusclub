<?php

namespace App\Console\Commands;

use App\Domain\Icafe\Client;
use App\Models\Club;
use Illuminate\Console\Command;

final class ConnectIcafe extends Command
{
    protected $signature = 'cclub:icafe-connect {club : Existing club ID} {license} {--currency=UZS}';
    protected $description = 'Connect an existing club; read API token from standard input, never from arguments';

    public function handle(Client $client): int
    {
        $club = Club::findOrFail($this->argument('club'));
        $license = filter_var($this->argument('license'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (! $license || ! preg_match('/^[A-Z]{3}$/', $this->option('currency'))) return self::FAILURE;
        if (Club::where('icafe_license',$license)->whereKeyNot($club->id)->exists()) { $this->error('License already assigned'); return self::FAILURE; }
        $token = trim(stream_get_contents(STDIN));
        try { $client->get($license, $token, 'pcs'); }
        catch (\Throwable) { $this->error('Connection failed; credentials not saved'); return self::FAILURE; }
        $club->icafe_license=$license;$club->icafe_token=$token;$club->icafe_currency=$this->option('currency');$club->save();
        $this->info('Connection verified and encrypted token saved.');
        return self::SUCCESS;
    }
}
