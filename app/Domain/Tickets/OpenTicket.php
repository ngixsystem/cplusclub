<?php

namespace App\Domain\Tickets;

use App\Models\{Ticket, User};
use Illuminate\Support\Facades\DB;

/** Creates a ticket the same way for the admin form, Telegram intake and link follow-ups. */
final class OpenTicket
{
    public function execute(array $data, int $initiatorId, array $extra = []): Ticket
    {
        return DB::transaction(function () use ($data, $initiatorId, $extra) {
            $ticket = new Ticket([...$data, 'initiator_id' => $initiatorId]);
            foreach ($extra as $k => $v) $ticket->$k = $v;
            $ticket->save();
            if ($ticket->priority === 'critical') {
                $ticket->assignee_id ??= User::where('role', 'lead')->where('active', true)->orderBy('id')->value('id');
                $ticket->save();
                DB::table('outbox_events')->insert([
                    'club_id' => $ticket->club_id, 'kind' => 'critical_ticket',
                    'dedup_key' => 'critical-ticket:'.$ticket->id,
                    'payload' => json_encode(['ticket_id' => $ticket->id], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }
            return $ticket;
        });
    }
}
