<?php

namespace App\Domain\Telegram;

use App\Domain\Tickets\OpenTicket;
use App\Models\{Attachment, Equipment, TelegramChannel, Ticket};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Cache, DB, RateLimiter, Storage};
use Illuminate\Support\Str;

/**
 * One Telegram update read by the poller: button presses (link follow-up, ticket rating),
 * tickets sent to a club group with /ticket or «заявка …», photos and replies to them.
 * Only chats that are connected as a channel of the same bot are acted on.
 */
final class UpdateHandler
{
    private const MAX_PHOTO = 10 * 1024 * 1024;

    public function __construct(private TelegramApi $api, private LinkFollowups $followups, private OpenTicket $open) {}

    public function handle(string $token, array $u): void
    {
        $hash = TelegramApi::hash($token);
        $chat = $u['message']['chat'] ?? $u['my_chat_member']['chat'] ?? $u['channel_post']['chat'] ?? $u['callback_query']['message']['chat'] ?? null;
        if (is_array($chat) && in_array($chat['type'] ?? '', ['group', 'supergroup', 'channel'], true)) {
            DB::table('telegram_seen_chats')->upsert([['token_hash' => $hash, 'chat_id' => (string) $chat['id'],
                'title' => mb_substr((string) ($chat['title'] ?? $chat['id']), 0, 255), 'type' => (string) $chat['type'], 'seen_at' => now()]],
                ['token_hash', 'chat_id'], ['title', 'type', 'seen_at']);
        }
        if (isset($u['callback_query'])) $this->callback($token, $hash, $u['callback_query']);
        elseif (isset($u['message'])) $this->message($token, $hash, $u['message']);
    }

    /** @return Collection<int,TelegramChannel> */
    private function channels(string $hash, string $chatId): Collection
    {
        return TelegramChannel::where('token_hash', $hash)->where('chat_id', $chatId)->where('enabled', true)->orderBy('id')->get();
    }

    private static function who(array $from): string
    {
        $name = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? ''));
        if ($name === '' && isset($from['username'])) $name = '@'.$from['username'];
        return mb_substr($name !== '' ? $name : 'Сотрудник клуба', 0, 120);
    }

    private function callback(string $token, string $hash, array $q): void
    {
        $data = (string) ($q['data'] ?? '');
        $chatId = (string) ($q['message']['chat']['id'] ?? '');
        $messageId = $q['message']['message_id'] ?? null;
        $original = (string) ($q['message']['text'] ?? '');
        $who = self::who($q['from'] ?? []);
        $channels = $chatId !== '' ? $this->channels($hash, $chatId) : collect();
        $result = ['answer' => 'Кнопка устарела.', 'text' => null, 'buttons' => []];

        if ($channels->isNotEmpty() && preg_match('/^lf:(\d+):(ok|fail)$/', $data, $m)) {
            $clubId = DB::table('link_followups')->where('id', (int) $m[1])->value('club_id');
            $channel = $channels->firstWhere('club_id', $clubId) ?? $channels->first();
            $result = $this->followups->respond((int) $m[1], $channel, $who, $m[2], $original);
        } elseif ($channels->isNotEmpty() && preg_match('/^rt:(\d+):([1-5])$/', $data, $m)) {
            $result = $this->rate((int) $m[1], (int) $m[2], $channels, $who, $original);
        }

        $this->api->call($token, 'answerCallbackQuery', ['callback_query_id' => (string) ($q['id'] ?? ''), 'text' => $result['answer']]);
        if ($result['text'] !== null && $messageId) {
            $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => mb_substr($result['text'], 0, 4000)];
            if ($result['buttons']) $params['reply_markup'] = TelegramApi::keyboard($result['buttons']);
            $this->api->call($token, 'editMessageText', $params);
        }
    }

    private function rate(int $ticketId, int $stars, Collection $channels, string $who, string $original): array
    {
        $ticket = Ticket::whereKey($ticketId)->whereIn('source_channel_id', $channels->pluck('id'))->first();
        if (! $ticket) return ['answer' => 'Эта кнопка не относится к этой группе.', 'text' => null, 'buttons' => []];
        if ($ticket->status !== 'closed') return ['answer' => 'Заявка снова в работе — оценить можно после закрытия.', 'text' => null, 'buttons' => []];
        $first = $ticket->rating === null;
        DB::table('tickets')->where('id', $ticket->id)->update(['rating' => $stars, 'rated_by' => $who, 'rated_at' => now()]);
        $ticket->events()->create(['actor_id' => BotUser::id(), 'from_status' => $ticket->status, 'to_status' => $ticket->status,
            'comment' => 'Оценка из Telegram: '.$stars.'/5 ('.$who.')']);
        DB::table('outbox_events')->insertOrIgnore(['club_id' => $ticket->club_id, 'kind' => 'ticket_rated',
            'dedup_key' => 'ticket-rated:'.$ticket->id.':'.now()->timestamp.':'.$stars,
            'payload' => json_encode(['ticket_id' => $ticket->id, 'rating' => $stars,
                'message' => 'Оценка '.$stars.'/5 '.TicketNotices::stars($stars).' по заявке #'.$ticket->id.' от '.$who.($first ? '' : ' (изменена)')], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now()]);
        $text = preg_replace('/\n*Оцените, пожалуйста, работу специалиста:\s*$/u', '', $original);
        $text = preg_replace('/\n*Оценка: [★☆]{5}.*$/u', '', (string) $text);
        return ['answer' => 'Спасибо за оценку!', 'text' => $text."\n\nОценка: ".TicketNotices::stars($stars).' — '.$who.'. Спасибо!', 'buttons' => []];
    }

    private function message(string $token, string $hash, array $m): void
    {
        if (! in_array($m['chat']['type'] ?? '', ['group', 'supergroup'], true) || ! empty($m['from']['is_bot'])) return;
        $chatId = (string) $m['chat']['id'];
        $channel = $this->channels($hash, $chatId)->first(fn ($c) => in_array('ticket_intake', (array) $c->kinds, true));
        if (! $channel) return;
        $text = trim((string) ($m['text'] ?? $m['caption'] ?? ''));
        $who = self::who($m['from'] ?? []);
        $photo = self::photo($m);

        // Further photos of an album arrive as separate messages without a caption.
        if (isset($m['media_group_id']) && $text === '' && $photo && ($id = Cache::get('tg-album:'.$chatId.':'.$m['media_group_id']))) {
            if ($t = Ticket::find($id)) $this->attach($token, $t, $photo);
            return;
        }

        $reply = $m['reply_to_message'] ?? null;
        if ($reply && ! empty($reply['from']['is_bot'])) {
            $ticket = Ticket::where('source_channel_id', $channel->id)->where('bot_message_id', (string) $reply['message_id'])->first();
            if ($ticket) { $this->follow($token, $channel, $ticket, $m, $text, $photo, $who); return; }
        }

        if (! preg_match('~^(?:/(?:ticket|zayavka)(?:@\w+)?|#?заявка)(?=$|[\s:,.!-])[\s:,.!-]*(.*)$~uis', $text, $mm)) return;
        $body = trim($mm[1]);
        if ($body === '') {
            $this->say($token, $chatId, "Опишите проблему одним сообщением, например:\n/ticket ПК 12 не запускается игра\n\nМожно приложить фото — отправьте его с такой подписью.", $m['message_id'] ?? null);
            return;
        }
        $limit = 'tg-intake:'.$channel->id;
        if (RateLimiter::tooManyAttempts($limit, 20)) {
            $this->say($token, $chatId, 'Слишком много заявок за час. Позвоните нам, если что-то срочное.', $m['message_id'] ?? null);
            return;
        }
        RateLimiter::hit($limit, 3600);

        $equipment = self::equipment($channel->club_id, $body);
        $ticket = $this->open->execute([
            'club_id' => $channel->club_id, 'equipment_id' => $equipment?->id, 'category' => self::category($body),
            'priority' => preg_match('/срочно|не работает весь|весь клуб|все пк|нет интернета/ui', $body) ? 'high' : 'normal',
            'description' => mb_substr($body, 0, 10000),
        ], BotUser::id(), ['source' => 'telegram', 'source_channel_id' => $channel->id, 'source_message_id' => (string) ($m['message_id'] ?? ''),
            'reporter_name' => $who]);
        if ($photo) $this->attach($token, $ticket, $photo);
        if (isset($m['media_group_id'])) Cache::put('tg-album:'.$chatId.':'.$m['media_group_id'], $ticket->id, now()->addMinutes(10));

        $where = $equipment ? ' · '.implode(' · ', array_filter([$equipment->name, $equipment->workstation_number ? 'место '.$equipment->workstation_number : null, $equipment->zone])) : '';
        $sent = $this->say($token, $chatId, "📝 Заявка #{$ticket->id} принята{$where}.\nСообщим здесь, когда специалист возьмёт её в работу."
            .($equipment ? '' : "\nЕсли проблема на конкретном ПК, напишите его номер ответом на это сообщение.")
            ."\nФото можно прислать ответом на это сообщение.", $m['message_id'] ?? null);
        if ($sent) DB::table('tickets')->where('id', $ticket->id)->update(['bot_message_id' => $sent]);
    }

    /** A reply to the bot's «Заявка принята» message: a photo, a PC number or a comment. */
    private function follow(string $token, TelegramChannel $channel, Ticket $ticket, array $m, string $text, ?array $photo, string $who): void
    {
        $notes = [];
        if ($photo && $this->attach($token, $ticket, $photo)) $notes[] = 'фото добавлено';
        if ($text !== '') {
            if (! $ticket->equipment_id && ($e = self::equipment($channel->club_id, $text))) {
                DB::table('tickets')->where('id', $ticket->id)->update(['equipment_id' => $e->id, 'updated_at' => now()]);
                $notes[] = 'привязали к '.$e->name;
            }
            $ticket->events()->create(['actor_id' => BotUser::id(), 'from_status' => $ticket->status, 'to_status' => $ticket->status,
                'comment' => mb_substr('Telegram, '.$who.': '.$text, 0, 5000)]);
            $notes[] = 'комментарий сохранён';
        }
        if ($notes) $this->say($token, (string) $m['chat']['id'], 'Заявка #'.$ticket->id.': '.implode(', ', $notes).'.', $m['message_id'] ?? null);
    }

    private static function photo(array $m): ?array
    {
        if (! empty($m['photo']) && is_array($m['photo'])) {
            $p = end($m['photo']);
            return isset($p['file_id']) ? ['id' => (string) $p['file_id'], 'size' => (int) ($p['file_size'] ?? 0)] : null;
        }
        if (isset($m['document']['file_id']) && str_starts_with((string) ($m['document']['mime_type'] ?? ''), 'image/')) {
            return ['id' => (string) $m['document']['file_id'], 'size' => (int) ($m['document']['file_size'] ?? 0)];
        }
        return null;
    }

    private function attach(string $token, Ticket $ticket, array $photo): bool
    {
        if ($photo['size'] > self::MAX_PHOTO) return false;
        $bytes = $this->api->download($token, $photo['id'], self::MAX_PHOTO);
        if ($bytes === null) return false;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if (! $ext) return false;
        $path = 'attachments/'.Str::uuid().'.'.$ext;
        Storage::disk('local')->put($path, $bytes);
        try {
            $a = new Attachment();
            $a->club_id = $ticket->club_id; $a->uploaded_by = BotUser::id(); $a->ticket_id = $ticket->id;
            $a->path = $path; $a->name = 'telegram-'.$ticket->id.'-'.now()->format('Ymd-His').'.'.$ext; $a->mime = $mime; $a->size = strlen($bytes);
            $a->save();
        } catch (\Throwable $e) { Storage::disk('local')->delete($path); throw $e; }
        return true;
    }

    /** Sends a message to the chat now and returns its id (null on failure). */
    private function say(string $token, string $chatId, string $text, $replyTo): ?string
    {
        $params = ['chat_id' => $chatId, 'text' => $text];
        if ($replyTo) $params['reply_parameters'] = ['message_id' => (int) $replyTo, 'allow_sending_without_reply' => true];
        $r = $this->api->call($token, 'sendMessage', $params);
        return $r['ok'] && isset($r['result']['message_id']) ? (string) $r['result']['message_id'] : null;
    }

    /** «ПК 12», «pc-027», «место 5», «комп 3» → the club's PC with that number. */
    public static function equipment(int $clubId, string $text): ?Equipment
    {
        if (! preg_match('/(?:пк|pc|комп(?:ьютер)?|место|№)\s*[-#№]?\s*0*(\d{1,4})\b/iu', $text, $m)) return null;
        $n = (int) $m[1];
        $pcs = Equipment::where('club_id', $clubId)->where('type', 'pc')->get(['id', 'name', 'workstation_number', 'zone']);
        return $pcs->first(fn ($e) => $e->workstation_number !== null && ctype_digit((string) $e->workstation_number) && (int) $e->workstation_number === $n)
            ?? $pcs->first(fn ($e) => preg_match('/(\d+)\s*$/', (string) $e->name, $d) && (int) $d[1] === $n);
    }

    public static function category(string $text): string
    {
        return match (true) {
            (bool) preg_match('/интернет|сеть|сетев|пинг|лаг|кабель|wi-?fi|роутер/ui', $text) => 'Сеть',
            (bool) preg_match('/монитор|клав|мыш|наушн|гарнитур|микрофон|джойстик|геймпад|руль/ui', $text) => 'Периферия',
            (bool) preg_match('/игр|steam|стим|запуск|лаунчер|обновлен|вылета|faceit|dota|cs2|valorant/ui', $text) => 'Программы и игры',
            (bool) preg_match('/не включ|не запуска.*пк|синий экран|перезагруж|гуди|шум|перегрев|греется/ui', $text) => 'Железо',
            default => 'Обращение из Telegram',
        };
    }
}
