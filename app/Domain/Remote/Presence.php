<?php

namespace App\Domain\Remote;

use App\Models\RemoteHost;

/** Asks the RustDesk server which enrolled hosts are online and stores the answer. */
final class Presence
{
    /** @return array<int, bool>|null host id => online; null when the RustDesk server didn't answer */
    public static function refresh(?iterable $hosts = null): ?array
    {
        $hosts = collect($hosts ?? RemoteHost::whereNotNull('rustdesk_id')->get())->filter(fn ($h) => $h->rustdesk_id);
        if ($hosts->isEmpty()) return [];
        $states = RustDesk::online($hosts->pluck('rustdesk_id')->all());
        if ($states === null) return null;
        $now = now(); $out = [];
        foreach ($hosts as $h) {
            $on = (bool) ($states[$h->rustdesk_id] ?? false);
            $out[$h->id] = $on;
            $changes = [];
            if ($h->online !== $on) { $changes['online'] = $on; $changes['status_changed_at'] = $now; }
            if ($on) $changes['last_seen_at'] = $now;
            if ($changes) RemoteHost::whereKey($h->id)->update($changes);
        }
        return $out;
    }
}
