<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TrainingLabAttempt extends Model
{
    protected $fillable = ['user_id', 'kind', 'subject', 'payload', 'answers', 'result', 'score', 'passed', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'answers' => 'array', 'result' => 'array', 'passed' => 'boolean', 'score' => 'integer',
            'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
