<?php

namespace App\Jobs;

use App\Domain\Icafe\BillingLogSync;
use App\Models\Club;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Every minute: new billing events; while history is incomplete, one more slice of it. */
class SyncBillingLogs implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 80;
    public int $uniqueFor = 120;

    public function __construct(public int $clubId) { $this->onQueue('billing'); }

    public function uniqueId(): string { return (string) $this->clubId; }

    public function handle(BillingLogSync $sync): void
    {
        $club = Club::find($this->clubId);
        if (! $club) return;
        try {
            $sync->live($club);
            $sync->backfill($club);
        } catch (\RuntimeException $e) {
            $sync->fail($club, $e->getMessage());   // upstream hiccup: the next minute retries from the saved place
        }
    }
}
