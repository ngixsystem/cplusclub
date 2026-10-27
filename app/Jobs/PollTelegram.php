<?php

namespace App\Jobs;

use App\Domain\Telegram\{TelegramApi, UpdateHandler};
use App\Models\TelegramChannel;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{Cache, DB};

/**
 * Reads updates for every connected bot with long polling (getUpdates) for ~40 s, so
 * button presses are answered within a second or two. Dispatched every minute.
 * The offset is saved before an update is handled: a crash drops one update instead of
 * opening the same ticket twice.
 */
class PollTelegram implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 85;
    public int $uniqueFor = 90;

    public function __construct(public int $seconds = 40) { $this->onQueue('telegram'); }

    public function handle(TelegramApi $api, UpdateHandler $handler): void
    {
        if (TelegramApi::fake()) return;
        $deadline = microtime(true) + $this->seconds;
        do {
            $bots = TelegramChannel::where('enabled', true)->orderBy('id')->get()->unique('token_hash');
            if ($bots->isEmpty()) return;
            $wait = $bots->count() === 1 ? 20 : 2;
            foreach ($bots as $bot) {
                $hash = $bot->token_hash;
                if (Cache::has('tg-poll:pause:'.$hash)) continue;
                DB::table('telegram_bot_state')->insertOrIgnore(['token_hash' => $hash]);
                $offset = (int) DB::table('telegram_bot_state')->where('token_hash', $hash)->value('update_offset');
                $r = $api->call($bot->bot_token, 'getUpdates', ['offset' => $offset, 'timeout' => $wait, 'limit' => 50,
                    'allowed_updates' => ['message', 'callback_query', 'my_chat_member']], $wait + 10);
                if (! $r['ok']) {
                    $error = match ($r['status']) { 409 => 'webhook', 401, 404 => 'token', 429 => 'rate', default => 'unavailable' };
                    DB::table('telegram_bot_state')->where('token_hash', $hash)->update(['last_error' => $error, 'polled_at' => now()]);
                    Cache::put('tg-poll:pause:'.$hash, true, now()->addSeconds(match ($error) { 'webhook', 'token' => 600, 'rate' => $r['retry_after'] ?? 60, default => 30 }));
                    continue;
                }
                DB::table('telegram_bot_state')->where('token_hash', $hash)->update(['last_error' => null, 'polled_at' => now()]);
                foreach ((array) $r['result'] as $update) {
                    if (! isset($update['update_id'])) continue;
                    DB::table('telegram_bot_state')->where('token_hash', $hash)->update(['update_offset' => (int) $update['update_id'] + 1]);
                    try { $handler->handle($bot->bot_token, $update); } catch (\Throwable $e) { report($e); }
                }
            }
        } while (microtime(true) < $deadline);
    }
}
