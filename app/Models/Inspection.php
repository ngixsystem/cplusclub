<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Inspection extends Model {
    protected $fillable = ['visit_id','club_id','equipment_id'];
    protected function casts(): array { return ['checklist'=>'array']; }
    public function visit() { return $this->belongsTo(Visit::class); }
    public function equipment() { return $this->belongsTo(Equipment::class); }
}
