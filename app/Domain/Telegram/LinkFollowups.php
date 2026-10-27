<?php

namespace App\Domain\Telegram;

use App\Domain\Monitoring\LinkMessages;
use App\Domain\Tickets\OpenTicket;
use App\Models\{TelegramChannel, Ticket, User};
use Illuminate\Support\Facades\DB;

/**
 * What happens after the club group is asked to reseat a cable: the «Переподключил» /
 * «Не помогло» buttons, and a ticket when it did not help, when the link is still slow
 * 10 minutes after «Переподключил», or when nobody answered within an hour.
 */
final class LinkFollowups
{
    public const RECHECK_MINUTES = 10;
    public const SILENCE_MINUTES = 60;

    public function __construct(private OpenTicket $open) {}

    /** @return list<list<array{0:string,1:string}>> */
    public static function buttons(int $id, bool $reconnected = false): array
    {
        return [[...($reconnected ? [] : [['✅ Переподключил', 'lf:'.$id.':ok']]), ['❌ Не помогло', 'lf:'.$id.':fail']]];
    }

    public static function open(int $alertId, int $episode, int $clubId, int $equipmentId, string $place, int $link, int $expected): int
    {
        DB::table('link_followups')->insertOrIgnore(['alert_id' => $alertId, 'episode' => $episode, 'club_id' => $clubId,
            'equipment_id' => $equipmentId, 'place' => mb_substr($place, 0, 255), 'link_mbps' => $link, 'expected_mbps' => $expected,
            'state' => 'waiting', 'created_at' => now(), 'updated_at' => now()]);
        return (int) DB::table('link_followups')->where('alert_id', $alertId)->where('episode', $episode)->value('id');
    }

    public static function recovered(int $alertId, int $episode): void
    {
        DB::table('link_followups')->where('alert_id', $alertId)->where('episode', $episode)
            ->whereIn('state', ['waiting', 'reconnected'])->update(['state' => 'recovered', 'updated_at' => now()]);
    }

    /**
     * A button press in the club group.
     * @return array{answer:string,text:?string,buttons:list<mixed>}  text null = leave the message as is
     */
    public function respond(int $id, TelegramChannel $channel, string $who, string $action, string $original): array
    {
        return DB::transaction(function () use ($id, $channel, $who, $action, $original) {
            $f = DB::table('link_followups')->where('id', $id)->lockForUpdate()->first();
            if (! $f || $f->club_id !== $channel->club_id) return ['answer' => 'Эта кнопка не относится к этой группе.', 'text' => null, 'buttons' => []];
            $time = now(DB::table('clubs')->where('id', $f->club_id)->value('timezone') ?: 'Asia/Tashkent')->format('H:i');
            if ($f->state === 'ticketed') return ['answer' => 'Заявка #'.$f->ticket_id.' уже создана, специалист в курсе.', 'text' => $original, 'buttons' => []];
            $active = DB::table('alerts')->where('id', $f->alert_id)->where('state', 'active')->where('episode', $f->episode)->exists();
            if ($f->state === 'recovered' || ! $active) {
                self::recovered($f->alert_id, $f->episode);
                return ['answer' => 'Линк уже восстановлен 👍', 'text' => $original, 'buttons' => []];
            }
            if ($action === 'ok') {
                if ($f->state === 'reconnected') return ['answer' => 'Уже отмечено, проверяем линк.', 'text' => null, 'buttons' => []];
                DB::table('link_followups')->where('id', $id)->update(['state' => 'reconnected', 'responder' => $who, 'responded_at' => now(),
                    'channel_id' => $channel->id, 'updated_at' => now()]);
                return ['answer' => 'Спасибо! Проверим линк через пару минут.',
                    'text' => $original."\n\n🔌 {$who} переподключил кабель в {$time}. Проверяем линк — если за ".self::RECHECK_MINUTES.' минут скорость не вернётся, создадим заявку.',
                    'buttons' => self::buttons($id, true)];
            }
            $ticket = $this->ticket($f, "Персонал клуба ({$who}) сообщил: переподключение кабеля не помогло.", $channel->id, $who);
            return ['answer' => 'Создана заявка #'.$ticket->id,
                'text' => $original."\n\n❌ {$who}: не помогло ({$time}). Создана заявка #{$ticket->id} — специалист C+CLub уже в курсе.", 'buttons' => []];
        });
    }

    /** Scheduled every minute: tickets for links that stayed slow. Returns how many were opened. */
    public function sweep(): int
    {
        // Episodes that ended some other way (recovered during maintenance, alert reset) are done.
        DB::table('link_followups as f')->whereIn('f.state', ['waiting', 'reconnected'])
            ->whereNotExists(fn ($q) => $q->from('alerts as a')->whereColumn('a.id', 'f.alert_id')->whereColumn('a.episode', 'f.episode')->where('a.state', 'active'))
            ->update(['state' => 'recovered', 'updated_at' => now()]);

        $due = DB::table('link_followups as f')->join('equipment as e', 'e.id', '=', 'f.equipment_id')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('f.state', 'reconnected')->where('f.responded_at', '<=', now()->subMinutes(self::RECHECK_MINUTES)))
                ->orWhere(fn ($q) => $q->where('f.state', 'waiting')->where('f.created_at', '<=', now()->subMinutes(self::SILENCE_MINUTES))))
            // Only while the PC is on and still reporting the slow link; a switched-off PC waits.
            ->where('e.last_seen_at', '>=', now()->subMinutes(5))
            // Only clubs whose group was actually asked to check the cable.
            ->whereExists(fn ($q) => $q->from('telegram_channels as c')->whereColumn('c.club_id', 'f.club_id')->where('c.enabled', true)->whereJsonContains('c.kinds', 'link_down'))
            ->orderBy('f.id')->limit(50)->pluck('f.id');

        $opened = 0;
        foreach ($due as $id) {
            DB::transaction(function () use ($id, &$opened) {
                $f = DB::table('link_followups')->where('id', $id)->lockForUpdate()->first();
                if (! in_array($f->state, ['waiting', 'reconnected'], true)) return;
                $reason = $f->state === 'reconnected'
                    ? "Персонал клуба ({$f->responder}) переподключил кабель, но за ".self::RECHECK_MINUTES.' минут скорость не восстановилась.'
                    : 'За '.self::SILENCE_MINUTES.' минут в группе клуба никто не отметил переподключение, скорость не восстановилась.';
                $channel = $f->channel_id ?: DB::table('telegram_channels')->where('club_id', $f->club_id)->where('enabled', true)->whereJsonContains('kinds', 'link_down')->orderBy('id')->value('id');
                $ticket = $this->ticket($f, $reason, $channel, $f->responder);
                $message = "📝 {$f->place}\nСкорость сети всё ещё ".LinkMessages::speed($f->link_mbps)." — создали заявку #{$ticket->id}. Специалист C+CLub свяжется с вами.";
                DB::table('outbox_events')->insertOrIgnore(['club_id' => $f->club_id, 'kind' => 'link_ticket', 'dedup_key' => 'link-followup:'.$f->id.':ticket',
                    'payload' => json_encode(['equipment_id' => $f->equipment_id, 'ticket_id' => $ticket->id, 'message' => $message], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'created_at' => now()]);
                $opened++;
            });
        }
        return $opened;
    }

    private function ticket(object $f, string $reason, ?int $channelId, ?string $who): Ticket
    {
        $tz = DB::table('clubs')->where('id', $f->club_id)->value('timezone') ?: 'Asia/Tashkent';
        $opened = \Carbon\CarbonImmutable::parse($f->created_at)->setTimezone($tz)->format('d.m H:i');
        $specialist = DB::table('clubs')->where('id', $f->club_id)->value('specialist_id');
        $active = $specialist && User::whereKey($specialist)->where('active', true)->exists();
        $ticket = $this->open->execute([
            'club_id' => $f->club_id, 'equipment_id' => $f->equipment_id, 'category' => 'Сеть', 'priority' => 'high',
            'description' => "Сетевой линк упал до ".LinkMessages::speed($f->link_mbps).' (норма '.LinkMessages::speed($f->expected_mbps).") — {$f->place}.\n"
                ."Замечено: {$opened}.\n{$reason}\nПроверьте патч-корд, порт коммутатора и сетевую карту.",
        ], BotUser::id(), ['source' => 'link', 'source_channel_id' => $channelId, 'reporter_name' => $who ? mb_substr($who, 0, 120) : null,
            'assignee_id' => $active ? $specialist : null]);
        DB::table('link_followups')->where('id', $f->id)->update(['state' => 'ticketed', 'ticket_id' => $ticket->id, 'updated_at' => now()]);
        return $ticket;
    }
}
