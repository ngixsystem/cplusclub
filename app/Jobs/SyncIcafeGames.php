<?php

namespace App\Jobs;

use App\Domain\Games\IcafeGames;
use App\Models\Club;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Hourly: the club's game list and launch counters from iCafeCloud. */
class SyncIcafeGames implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 40;
    public int $uniqueFor = 600;

    public function __construct(public int $clubId) { $this->onQueue('integrations'); }

    public function uniqueId(): string { return (string) $this->clubId; }

    public function handle(IcafeGames $games): void
    {
        $club = Club::find($this->clubId);
        if (! $club) return;
        try { $games->sync($club); } catch (\RuntimeException) { /* upstream hiccup: next hour retries */ }
    }
}
