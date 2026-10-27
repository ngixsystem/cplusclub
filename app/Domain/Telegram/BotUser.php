<?php

namespace App\Domain\Telegram;

use App\Models\User;
use Illuminate\Support\Str;

/** The account tickets opened from Telegram are filed under. It cannot sign in (inactive). */
final class BotUser
{
    public const EMAIL = 'telegram-bot@system.cplusclub.tech';

    public static function id(): int
    {
        $user = User::withTrashed()->where('email', self::EMAIL)->first();
        if ($user) return $user->id;
        $user = new User();
        $user->name = 'Telegram-бот'; $user->email = self::EMAIL;
        $user->password = Str::random(64); $user->role = 'representative'; $user->active = false;
        $user->save();
        return $user->id;
    }
}
