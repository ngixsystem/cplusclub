<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class QuizAttempt extends Model
{
    protected $fillable = ['user_id', 'kind', 'subject', 'questions', 'answers', 'correct', 'total', 'points', 'max_points', 'percent', 'passed', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return [
            'questions' => 'array', 'answers' => 'array', 'passed' => 'boolean',
            'started_at' => 'datetime', 'finished_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
