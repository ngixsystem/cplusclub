<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TrainingEnrollment extends Model
{
    protected $fillable = ['user_id', 'track_id', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
