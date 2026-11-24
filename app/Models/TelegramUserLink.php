<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TelegramUserLink extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'token_hash', 'chat_id', 'username', 'linked_at'];

    protected $hidden = ['token_hash', 'chat_id'];

    protected function casts(): array
    {
        return ['linked_at' => 'datetime'];
    }
}
