<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    public function isCleaning(): bool { return (bool) preg_match('/чистк/iu', $this->category ?? ''); }
    protected $fillable = ['club_id', 'equipment_id', 'category', 'description', 'priority', 'initiator_id', 'assignee_id'];
    public function club() { return $this->belongsTo(Club::class)->withoutGlobalScope('real'); }
    public function equipment() { return $this->belongsTo(Equipment::class)->withoutGlobalScope('real'); }
    public function isTraining(): bool { return $this->training_user_id !== null; }

    /** Practice tickets exist only for the trainee who started them; everyone else (and every report) never sees them. */
    protected static function booted(): void
    {
        static::addGlobalScope('real', function (\Illuminate\Database\Eloquent\Builder $q) {
            $q->where(fn ($w) => $w->whereNull('tickets.training_user_id')
                ->when(auth()->id(), fn ($w, $id) => $w->orWhere('tickets.training_user_id', $id)));
        });
    }
    public function assignee() { return $this->belongsTo(User::class, 'assignee_id')->withTrashed(); }
    public function events() { return $this->hasMany(TicketEvent::class); }
}
