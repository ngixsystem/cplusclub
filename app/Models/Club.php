<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Club extends Model
{
    protected $hidden = ['icafe_token', 'map_layout'];
    protected function casts(): array { return ['icafe_token' => 'encrypted', 'map_layout' => 'array']; }
    protected $fillable = ['name', 'address', 'timezone', 'contacts', 'specialist_id', 'support_hours', 'status', 'notes'];
    public function members() { return $this->belongsToMany(User::class); }
}
