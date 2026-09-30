<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Visit extends Model {
    protected $fillable = ['club_id','specialist_id','planned_date'];
    public function club() { return $this->belongsTo(Club::class); }
    public function specialist() { return $this->belongsTo(User::class, 'specialist_id')->withTrashed(); }
    public function inspections() { return $this->hasMany(Inspection::class); }
}
