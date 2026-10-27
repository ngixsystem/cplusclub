<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TelegramChannel extends Model {
    protected $hidden=['bot_token','token_hash'];
    protected function casts():array {return ['bot_token'=>'encrypted','kinds'=>'array','enabled'=>'boolean'];}

    /** Kinds a channel receives without a separate checkbox: they follow the subscription they belong to. */
    public const IMPLIED=['link_ticket'=>'link_down'];
    /** Kinds that are only ever sent to the chat a ticket came from (payload channel_id). */
    public const DIRECT=['ticket_status','ticket_reply'];

    protected static function booted():void {
        static::saving(function(self $c){ if($c->isDirty('bot_token')||!$c->token_hash) $c->token_hash=hash('sha256',(string)$c->bot_token); });
    }

    /** Whether an outbox event of this kind (and payload) goes to this channel. */
    public function accepts(string $kind,?array $payload=null):bool {
        if(isset($payload['channel_id'])) return (int)$payload['channel_id']===$this->id;
        if(in_array($kind,self::DIRECT,true)) return false;
        $kinds=(array)$this->kinds;
        return in_array($kind,$kinds,true)||(isset(self::IMPLIED[$kind])&&in_array(self::IMPLIED[$kind],$kinds,true));
    }
}
