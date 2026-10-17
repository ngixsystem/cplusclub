<?php

namespace App\Jobs;

use App\Domain\Monitoring\IcafeBootTelemetry;
use App\Models\Club;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Pulls CPU/GPU temperatures for one club from iCafeCloud Boot. Scheduled every minute. */
class CollectIcafeBootTelemetry implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 50;
    public int $uniqueFor = 55;

    public function __construct(public int $clubId) { $this->onQueue('integrations'); }

    public function uniqueId(): string { return (string) $this->clubId; }

    public function handle(IcafeBootTelemetry $telemetry): void
    {
        $club = Club::find($this->clubId);
        if (! $club) return;
        try { $telemetry->collect($club); } catch (\RuntimeException) { /* upstream hiccup: the next minute retries */ }
    }
}
