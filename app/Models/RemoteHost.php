<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RemoteHost extends Model
{
    protected $hidden = ['password', 'enroll_token_hash'];
    protected function casts(): array { return ['password' => 'encrypted', 'enroll_expires_at' => 'datetime', 'enrolled_at' => 'datetime']; }
    public function club() { return $this->belongsTo(Club::class); }
}
