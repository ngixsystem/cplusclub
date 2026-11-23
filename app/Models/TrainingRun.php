<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TrainingRun extends Model
{
    protected $fillable = ['user_id', 'scenario_id', 'ticket_id', 'status', 'checks', 'reworks', 'unneeded_proposal', 'score', 'max_score', 'passed', 'breakdown', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return [
            'checks' => 'array', 'breakdown' => 'array', 'passed' => 'boolean', 'unneeded_proposal' => 'boolean',
            'started_at' => 'datetime', 'finished_at' => 'datetime',
        ];
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }
}
