<?php

namespace App\Domain\Telegram;

use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Status updates for tickets that came from a club Telegram group, sent back to that
 * group only (payload channel_id), plus the 1–5 rating request when a ticket closes.
 */
final class TicketNotices
{
    private const TEXT = [
        'accepted' => '👨‍🔧 Заявка #%d принята специалистом%s.',
        'working' => '🔧 Заявка #%d в работе%s.',
        'parts' => '📦 Заявка #%d: ждём запчасти, сообщим о сроках.',
        'resolved' => '✅ Заявка #%d выполнена%s. Проверьте, пожалуйста, всё ли работает.',
    ];

    public static function status(Ticket $ticket, string $from): void
    {
        if (! $ticket->source_channel_id || $ticket->status === $from) return;
        $who = $ticket->assignee_id ? DB::table('users')->where('id', $ticket->assignee_id)->value('name') : null;
        $by = $who ? ': '.$who : '';
        $payload = ['channel_id' => $ticket->source_channel_id, 'ticket_id' => $ticket->id];
        if ($ticket->status === 'closed') {
            $text = "✅ Заявка #{$ticket->id} закрыта.".(filled($ticket->solution) ? "\nЧто сделали: ".mb_substr(trim($ticket->solution), 0, 500) : '')
                ."\n\nОцените, пожалуйста, работу специалиста:";
            $payload += ['message' => $text, 'buttons' => [array_map(fn ($n) => ['⭐ '.$n, 'rt:'.$ticket->id.':'.$n], range(1, 5))]];
        } elseif (isset(self::TEXT[$ticket->status])) {
            $payload['message'] = sprintf(self::TEXT[$ticket->status], $ticket->id, in_array($ticket->status, ['accepted', 'working'], true) ? $by : '');
        } else {
            return;
        }
        DB::table('outbox_events')->insertOrIgnore([
            'club_id' => $ticket->club_id, 'kind' => 'ticket_status',
            'dedup_key' => 'ticket-status:'.$ticket->id.':v'.$ticket->version,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(),
        ]);
    }

    public static function stars(int $n): string { return str_repeat('★', $n).str_repeat('☆', 5 - $n); }
}
