<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, RateLimiter};
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer token → API client (one club, optional IP allow-list, scopes), 120 requests a
 * minute per client, every request logged. Errors are JSON: {"error":{"code","message"}}.
 */
class ExternalApi
{
    public const PER_MINUTE = 120;

    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $started = microtime(true);
        $client = ApiClient::findByToken($request->bearerToken());
        if (! $client) return self::error('unauthorized', 'Нет токена или токен недействителен.', 401);
        $response = $this->guarded($request, $next, $client, $scope);
        DB::table('api_requests')->insert(['api_client_id' => $client->id, 'method' => $request->method(), 'path' => mb_substr('/'.$request->path(), 0, 200),
            'status' => $response->getStatusCode(), 'query' => $request->getQueryString() !== null ? mb_substr($request->getQueryString(), 0, 500) : null, 'ip' => $request->ip(), 'ms' => (int) round((microtime(true) - $started) * 1000), 'created_at' => now()]);
        if ($response->getStatusCode() < 400) DB::table('api_clients')->where('id', $client->id)->update(['last_used_at' => now(), 'last_ip' => $request->ip()]);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }

    private function guarded(Request $request, Closure $next, ApiClient $client, ?string $scope): Response
    {
        if (! $client->allowsIp($request->ip())) return self::error('ip_not_allowed', 'Запрос с этого IP-адреса не разрешён для токена.', 403);
        if ($scope && ! in_array($scope, $client->scopes ?? [], true)) return self::error('scope_denied', 'У токена нет доступа к этому разделу.', 403);
        $key = 'ext-api:'.$client->id;
        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            $r = self::error('rate_limited', 'Слишком много запросов: не больше '.self::PER_MINUTE.' в минуту.', 429);
            $r->headers->set('Retry-After', (string) RateLimiter::availableIn($key));
            return $r;
        }
        RateLimiter::hit($key, 60);
        $request->attributes->set('api_client', $client);
        return $next($request);
    }

    public static function error(string $code, string $message, int $status): Response
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status, [], JSON_UNESCAPED_UNICODE);
    }
}
