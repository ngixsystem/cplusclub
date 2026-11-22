<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiClient extends Model
{
    public const SCOPES = ['pcs', 'shifts'];

    protected $hidden = ['token_hash'];
    protected $casts = ['scopes' => 'array', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function club(): BelongsTo { return $this->belongsTo(Club::class); }

    /** Creates a client and returns the plain token, shown once. */
    public static function issue(array $attrs): array
    {
        $token = 'ccx_'.Str::random(44);
        $c = new self();
        $c->fill([]);
        foreach ($attrs as $k => $v) $c->{$k} = $v;
        $c->token_hash = hash('sha256', $token);
        $c->token_hint = substr($token, -4);
        $c->save();
        return [$c, $token];
    }

    public static function findByToken(?string $token): ?self
    {
        if (! $token || ! str_starts_with($token, 'ccx_')) return null;
        return self::where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
    }

    /** @return list<string> */
    public function ips(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->allowed_ips))));
    }

    public function allowsIp(?string $ip): bool
    {
        $list = $this->ips();
        if (! $list) return true;
        foreach ($list as $allowed) {
            if ($allowed === $ip) return true;
            if (str_contains($allowed, '/') && $ip && \Symfony\Component\HttpFoundation\IpUtils::checkIp($ip, $allowed)) return true;
        }
        return false;
    }
}
