<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Equipment extends Model
{
    protected $fillable = ['club_id', 'name', 'zone', 'workstation_number', 'type', 'cpu', 'gpu', 'ram_mb', 'disks', 'serial_number', 'inventory_number', 'ip', 'mac', 'status', 'last_inspection_date', 'next_inspection_date'];
    protected $table = 'equipment';
    public function club() { return $this->belongsTo(Club::class); }

    /** Hardware fields whose edits are kept in the PC passport history. */
    public const TRACKED = ['cpu', 'gpu', 'ram_mb', 'disks', 'motherboard', 'nic', 'serial_number', 'mac', 'status'];

    protected static function booted(): void
    {
        static::updated(function (self $e) {
            foreach (array_intersect(array_keys($e->getChanges()), self::TRACKED) as $field) {
                \Illuminate\Support\Facades\DB::table('equipment_changes')->insert(['equipment_id' => $e->id, 'field' => $field,
                    'old_value' => $e->getOriginal($field), 'new_value' => $e->$field, 'source' => 'admin', 'user_id' => auth()->id(), 'created_at' => now()]);
            }
        });
    }
}
