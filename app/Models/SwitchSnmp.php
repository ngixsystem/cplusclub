<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SwitchSnmp extends Model
{
    protected $table = 'switch_snmp';
    protected $primaryKey = 'equipment_id';
    public $incrementing = false;
    protected $fillable = ['equipment_id', 'host', 'port', 'version', 'community'];
    protected $hidden = ['community'];
    protected function casts(): array { return ['community' => 'encrypted', 'polled_at' => 'datetime']; }
}
