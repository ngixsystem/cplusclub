<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Club extends Model
{
    protected $fillable = ['name', 'address', 'timezone', 'contacts', 'specialist_id', 'support_hours', 'status', 'notes'];
    public function members() { return $this->belongsToMany(User::class); }
}

