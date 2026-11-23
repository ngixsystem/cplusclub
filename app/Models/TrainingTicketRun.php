<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TrainingTicketRun extends Model
{
    protected $fillable = ['user_id', 'scenario_id', 'mode', 'status', 'state', 'score', 'passed', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['state' => 'array', 'passed' => 'boolean', 'score' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
