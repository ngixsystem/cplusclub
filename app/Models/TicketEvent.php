<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketEvent extends Model
{
    protected $fillable = ['ticket_id', 'actor_id', 'from_status', 'to_status', 'comment'];
    public const UPDATED_AT = null;
}
