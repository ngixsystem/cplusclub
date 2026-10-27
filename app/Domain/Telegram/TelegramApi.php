<?php

namespace App\Domain\Telegram;

use Illuminate\Support\Facades\Http;

/**
 * Thin Bot API client. Raw HTTP exceptions contain the token URL, so they never escape:
 * every call returns an array with ok/status and, on success, the result.
 */
final class TelegramApi
{
    public static function hash(string $token): string { return hash('sha256', $token); }

    public static function fake(): bool { return config('services.telegram.transport') === 'fake'; }

    /** @return array{ok:bool,status:?int,result?:mixed,retry_after?:int,ambiguous?:bool,description?:string} */
    public function call(string $token, string $method, array $params = [], int $timeout = 15): array
    {
        if (self::fake()) {
            return ['ok' => true, 'status' => 200, 'result' => match ($method) {
                'getUpdates' => [], 'getMe' => ['username' => 'test_bot'],
                default => ['message_id' => random_int(1, 2_000_000_000)],
            }];
        }
        try {
            $r = Http::connectTimeout(5)->timeout($timeout)->post('https://api.telegram.org/bot'.$token.'/'.$method, $params);
            if ($r->status() === 429) return ['ok' => false, 'status' => 429, 'retry_after' => max(1, min(86400, (int) $r->json('parameters.retry_after', 60)))];
            return ['ok' => $r->successful() && $r->json('ok') === true, 'status' => $r->status(), 'result' => $r->json('result'),
                'description' => mb_substr((string) $r->json('description', ''), 0, 200)];
        } catch (\Throwable) {
            return ['ok' => false, 'status' => null, 'ambiguous' => true];
        }
    }

    /** Downloads a file the bot received (photo). Null when it is too big or unavailable. */
    public function download(string $token, string $fileId, int $maxBytes): ?string
    {
        if (self::fake()) return null;
        $file = $this->call($token, 'getFile', ['file_id' => $fileId]);
        $path = $file['result']['file_path'] ?? null;
        if (! $file['ok'] || ! is_string($path) || ! preg_match('~^[\w./-]+$~', $path) || (int) ($file['result']['file_size'] ?? 0) > $maxBytes) return null;
        try {
            $r = Http::connectTimeout(5)->timeout(30)->get('https://api.telegram.org/file/bot'.$token.'/'.$path);
            $body = $r->successful() ? $r->body() : '';
            return $body !== '' && strlen($body) <= $maxBytes ? $body : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Inline keyboard from [[label, callback_data], ...] rows. */
    public static function keyboard(array $rows): array
    {
        return ['inline_keyboard' => array_map(fn ($row) => array_map(fn ($b) => ['text' => (string) $b[0], 'callback_data' => (string) $b[1]], $row), $rows)];
    }
}
