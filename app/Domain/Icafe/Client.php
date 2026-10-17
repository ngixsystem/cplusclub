<?php

namespace App\Domain\Icafe;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class Client
{
    /** POST with a JSON body; same error hygiene as get(). */
    public function post(int $license, string $token, string $path, array $body): array
    {
        try {
            $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(20)
                ->withOptions(['allow_redirects' => false])
                ->post("https://api.icafecloud.com/api/v2/cafe/{$license}/{$path}", $body);
            if (! $response->successful() || (int) $response->json('code') !== 200 || ! is_array($response->json('data'))) {
                throw new RuntimeException('Upstream rejected request');
            }
            return $response->json('data');
        } catch (\Throwable) {
            throw new RuntimeException('iCafeCloud недоступен или отклонил запрос. Проверьте лицензию и API-токен.');
        }
    }

    public function get(int $license, string $token, string $path, array $query = []): array
    {
        // Never propagate upstream bodies or HTTP exceptions: they may contain credentials/PII.
        try {
            $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(15)
                ->withOptions(['allow_redirects' => false])
                ->get("https://api.icafecloud.com/api/v2/cafe/{$license}/{$path}", $query);
            if (! $response->successful() || (int) $response->json('code') !== 200 || ! is_array($response->json('data'))) {
                throw new RuntimeException('Upstream rejected request');
            }
            return $response->json('data');
        } catch (\Throwable) {
            throw new RuntimeException('iCafeCloud недоступен или отклонил запрос. Проверьте лицензию и API-токен.');
        }
    }
}
