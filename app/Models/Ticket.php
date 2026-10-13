<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    public function isCleaning(): bool { return (bool) preg_match('/чистк/iu', $this->category ?? ''); }
    protected $fillable = ['club_id', 'equipment_id', 'category', 'description', 'priority', 'initiator_id', 'assignee_id'];
    public function club() { return $this->belongsTo(Club::class); }
    public function equipment() { return $this->belongsTo(Equipment::class); }
    public function assignee() { return $this->belongsTo(User::class, 'assignee_id')->withTrashed(); }
    public function events() { return $this->hasMany(TicketEvent::class); }
}
