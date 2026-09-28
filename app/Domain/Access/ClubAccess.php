<?php

namespace App\Domain\Access;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ClubAccess
{
    public static function allClubs(User $user): bool
    {
        return in_array($user->role, ['owner', 'lead'], true);
    }

    public static function allows(User $user, int $clubId): bool
    {
        return self::allClubs($user) || $user->clubs()->whereKey($clubId)->exists();
    }

    public static function filter(Builder $query, User $user, string $column = 'club_id'): Builder
    {
        return self::allClubs($user) ? $query : $query->whereIn($column, $user->clubs()->select('clubs.id'));
    }
}
