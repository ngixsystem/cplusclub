<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TelegramChannel extends Model {
    protected $hidden=['bot_token'];
    protected function casts():array {return ['bot_token'=>'encrypted','kinds'=>'array','enabled'=>'boolean'];}
}
