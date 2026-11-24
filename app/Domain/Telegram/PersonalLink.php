<?php

namespace App\Domain\Telegram;

use App\Models\{TelegramChannel, TelegramUserLink, User};
use Illuminate\Support\Facades\{Cache, RateLimiter};

/**
 * Personal notifications: an employee links a private chat with one of the club bots by sending
 * /start <code> (a one-time code from the training page). Used for training reminders.
 */
final class PersonalLink
{
    public const CODE_MINUTES = 15;

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private TelegramApi $api) {}

    /** Bots that are polled (enabled in at least one channel) — the ones that can receive /start. */
    public function bots(): array
    {
        return TelegramChannel::where('enabled', true)->orderBy('id')->get()->unique('token_hash')
            ->map(fn (TelegramChannel $c) => Cache::remember('tg-me:'.$c->token_hash, now()->addDay(), function () use ($c) {
                $r = $this->api->call($c->bot_token, 'getMe');

                return $r['ok'] ? ($r['result']['username'] ?? null) : null;
            }))->filter()->unique()->values()->all();
    }

    public function issue(User $user): array
    {
        $old = Cache::pull('tg-personal-user:'.$user->id);
        if ($old) {
            Cache::forget('tg-personal:'.$old);
        }
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $hash = hash('sha256', $code);
        Cache::put('tg-personal:'.$hash, $user->id, now()->addMinutes(self::CODE_MINUTES));
        Cache::put('tg-personal-user:'.$user->id, $hash, now()->addMinutes(self::CODE_MINUTES));

        return ['code' => $code, 'expires_at' => now()->addMinutes(self::CODE_MINUTES)->toIso8601String(), 'bots' => $this->bots()];
    }

    public function state(User $user): array
    {
        $link = TelegramUserLink::where('user_id', $user->id)->first();

        return ['linked' => (bool) $link, 'username' => $link?->username, 'linked_at' => $link?->linked_at?->toIso8601String()];
    }

    public function unlink(User $user): void
    {
        TelegramUserLink::where('user_id', $user->id)->delete();
    }

    /** A private message to a bot. Returns true when it was a personal command. */
    public function handle(string $token, string $hash, array $m): bool
    {
        if (($m['chat']['type'] ?? '') !== 'private' || ! empty($m['from']['is_bot'])) {
            return false;
        }
        $chatId = (string) $m['chat']['id'];
        $text = trim((string) ($m['text'] ?? ''));
        if (preg_match('~^/stop(?:@\w+)?$~i', $text)) {
            $deleted = TelegramUserLink::where('token_hash', $hash)->where('chat_id', $chatId)->delete();
            $this->say($token, $chatId, $deleted ? 'Напоминания об обучении отключены. Подключить снова можно на странице «Обучение» в C+CLub.' : 'Этот чат не привязан к сотруднику C+CLub.');

            return true;
        }
        if (! preg_match('~^/start(?:@\w+)?(?:\s+([A-Za-z0-9]{6,12}))?$~', $text, $mm)) {
            return false;
        }
        if (empty($mm[1])) {
            $this->say($token, $chatId, 'Чтобы получать напоминания об обучении, откройте в C+CLub страницу «Обучение», нажмите «Подключить Telegram» и отправьте сюда команду с кодом.');

            return true;
        }
        $limit = 'tg-personal-try:'.$chatId;
        if (RateLimiter::tooManyAttempts($limit, 10)) {
            $this->say($token, $chatId, 'Слишком много попыток. Попробуйте через час.');

            return true;
        }
        RateLimiter::hit($limit, 3600);
        $hashCode = hash('sha256', strtoupper($mm[1]));
        $userId = Cache::pull('tg-personal:'.$hashCode);
        $user = $userId ? User::find($userId) : null;
        if (! $user || ! $user->active) {
            $this->say($token, $chatId, 'Код не подошёл или устарел. Получите новый на странице «Обучение» — он действует '.self::CODE_MINUTES.' минут.');

            return true;
        }
        Cache::forget('tg-personal-user:'.$user->id);
        TelegramUserLink::where('token_hash', $hash)->where('chat_id', $chatId)->where('user_id', '!=', $user->id)->delete();
        TelegramUserLink::updateOrCreate(['user_id' => $user->id], [
            'token_hash' => $hash, 'chat_id' => $chatId, 'linked_at' => now(),
            'username' => isset($m['from']['username']) ? mb_substr('@'.$m['from']['username'], 0, 64) : null,
        ]);
        $this->say($token, $chatId, "Готово, {$user->name}! Сюда будут приходить напоминания об обучении в C+CLub: назначенные программы, сроки и перерывы больше 3 дней. Отключить — /stop.");

        return true;
    }

    /** Sends a personal message. False when the employee has no link or the bot is gone or blocked. */
    public function send(User $user, string $text): bool
    {
        $link = TelegramUserLink::where('user_id', $user->id)->first();
        if (! $link) {
            return false;
        }
        $bot = TelegramChannel::where('token_hash', $link->token_hash)->where('enabled', true)->first();
        if (! $bot) {
            return false;
        }
        $r = $this->api->call($bot->bot_token, 'sendMessage', ['chat_id' => $link->chat_id, 'text' => mb_substr($text, 0, 4000), 'disable_web_page_preview' => true]);
        if (! $r['ok'] && in_array($r['status'], [400, 403], true)) {
            $link->delete(); // the employee blocked the bot or the chat no longer exists
        }

        return $r['ok'];
    }

    private function say(string $token, string $chatId, string $text): void
    {
        $this->api->call($token, 'sendMessage', ['chat_id' => $chatId, 'text' => $text]);
    }
}
