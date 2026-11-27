<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SnmpCollector extends Model
{
    protected $hidden = ['token_hash'];
    protected $casts = ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];

    /** @return array{0: self, 1: string} the collector and its plain token, shown once. */
    public static function issue(int $clubId, string $name): array
    {
        $token = 'ccs_'.Str::random(44);
        $c = new self();
        $c->club_id = $clubId; $c->name = $name;
        $c->token_hash = hash('sha256', $token); $c->token_hint = substr($token, -4);
        $c->save();
        return [$c, $token];
    }

    public static function findByToken(?string $token): ?self
    {
        if (! $token || ! str_starts_with($token, 'ccs_')) return null;
        return self::where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
    }
}
