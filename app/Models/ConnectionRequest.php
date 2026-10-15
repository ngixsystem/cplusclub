<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ConnectionRequest extends Model {
    protected $fillable=['request_id','name','club','contact','pc_count','plan','message'];
}
