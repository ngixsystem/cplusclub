<?php
namespace App\Domain\Notifications;
use App\Domain\Telegram\TelegramApi;
use App\Models\TelegramChannel;
use Illuminate\Support\Facades\{DB, Http};
class TelegramTransport {
    public function __construct(private TelegramApi $api) {}

    /** @param list<list<array{0:string,1:string}>> $buttons inline keyboard rows */
    public function send(TelegramChannel $channel,string $text,array $buttons=[]):array {
        if(TelegramApi::fake()) return ['ok'=>true,'status'=>200,'message_id'=>'fake-'.bin2hex(random_bytes(6))];
        $params=['chat_id'=>$channel->chat_id,'text'=>mb_substr($text,0,4000)];
        if($buttons) $params['reply_markup']=TelegramApi::keyboard($buttons);
        $r=$this->api->call($channel->bot_token,'sendMessage',$params);
        if(!empty($r['ambiguous'])) return ['ok'=>false,'status'=>null,'ambiguous'=>true];
        if(($r['status']??null)===429) return $r;
        return ['ok'=>$r['ok'],'status'=>$r['status'],'message_id'=>(string)($r['result']['message_id']??'')];
    }

    /** @return array{ok:bool,bot?:string,chats?:list<array{id:string,title:string,type:string}>,error?:string} */
    public function chats(string $token):array {
        if(TelegramApi::fake()) return ['ok'=>true,'bot'=>'test_bot','chats'=>[['id'=>'-1001234567890','title'=>'Тестовая группа','type'=>'supergroup']]];
        try {
            $me=Http::connectTimeout(5)->timeout(10)->get('https://api.telegram.org/bot'.$token.'/getMe');
            if($me->status()===401||$me->status()===404)return ['ok'=>false,'error'=>'Telegram не принял токен. Проверьте его в @BotFather.'];
            if(!$me->successful())return ['ok'=>false,'error'=>'Telegram не ответил. Попробуйте ещё раз через минуту.'];
            $hash=TelegramApi::hash($token);
            $chats=[];
            foreach(DB::table('telegram_seen_chats')->where('token_hash',$hash)->orderByDesc('seen_at')->limit(50)->get() as $c)
                $chats[$c->chat_id]=['id'=>$c->chat_id,'title'=>$c->title,'type'=>$c->type];
            // A bot that is already connected is read by the poller; asking Telegram again would
            // conflict with it, so the chats it has recorded are the answer.
            if(!DB::table('telegram_channels')->where('token_hash',$hash)->where('enabled',true)->exists()){
                $updates=Http::connectTimeout(5)->timeout(15)->get('https://api.telegram.org/bot'.$token.'/getUpdates',['allowed_updates'=>json_encode(['message','my_chat_member','channel_post'])]);
                if($updates->status()===409)return ['ok'=>false,'error'=>'У бота настроен webhook, поэтому список групп недоступен. Укажите Chat ID вручную.'];
                if(!$updates->successful())return ['ok'=>false,'error'=>'Telegram не ответил. Попробуйте ещё раз через минуту.'];
                foreach((array)$updates->json('result',[]) as $u){
                    $chat=$u['my_chat_member']['chat']??$u['message']['chat']??$u['channel_post']['chat']??null;
                    if(!is_array($chat)||!in_array($chat['type']??'',['group','supergroup','channel'],true))continue;
                    $chats[(string)$chat['id']]=['id'=>(string)$chat['id'],'title'=>(string)($chat['title']??$chat['id']),'type'=>(string)$chat['type']];
                }
            }
            return ['ok'=>true,'bot'=>(string)$me->json('result.username',''),'chats'=>array_values($chats)];
        }catch(\Throwable){return ['ok'=>false,'error'=>'Telegram не ответил. Попробуйте ещё раз через минуту.'];}
    }
}
