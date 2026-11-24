<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TrainingAssignment extends Model
{
    protected $fillable = ['user_id', 'track_id', 'assigned_by', 'due_on', 'note'];

    protected function casts(): array
    {
        return ['due_on' => 'date'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
