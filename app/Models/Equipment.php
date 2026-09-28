<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Equipment extends Model
{
    protected $fillable = ['club_id', 'name', 'zone', 'workstation_number', 'type', 'cpu', 'gpu', 'ram_mb', 'disks', 'serial_number', 'inventory_number', 'ip', 'mac', 'status', 'last_inspection_date', 'next_inspection_date'];
    protected $table = 'equipment';
    public function club() { return $this->belongsTo(Club::class); }
}

