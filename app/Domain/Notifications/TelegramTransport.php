<?php
namespace App\Domain\Notifications;
use App\Models\TelegramChannel;
use Illuminate\Support\Facades\Http;
class TelegramTransport {
    /** Never let raw HTTP exceptions (which contain the bot token URL) escape. */
    public function send(TelegramChannel $channel,string $text):array {
        if(config('services.telegram.transport')==='fake') return ['ok'=>true,'status'=>200,'message_id'=>'fake-'.bin2hex(random_bytes(6))];
        try {
            $response=Http::connectTimeout(5)->timeout(15)->post('https://api.telegram.org/bot'.$channel->bot_token.'/sendMessage',['chat_id'=>$channel->chat_id,'text'=>mb_substr($text,0,4000)]);
            if($response->status()===429)return ['ok'=>false,'status'=>429,'retry_after'=>max(1,min(86400,(int)$response->json('parameters.retry_after',60)))];
            return ['ok'=>$response->successful()&&$response->json('ok')===true,'status'=>$response->status(),'message_id'=>(string)$response->json('result.message_id','')];
        }catch(\Throwable){return ['ok'=>false,'status'=>null,'ambiguous'=>true];}
    }

    /** @return array{ok:bool,bot?:string,chats?:list<array{id:string,title:string,type:string}>,error?:string} */
    public function chats(string $token):array {
        if(config('services.telegram.transport')==='fake') return ['ok'=>true,'bot'=>'test_bot','chats'=>[['id'=>'-1001234567890','title'=>'Тестовая группа','type'=>'supergroup']]];
        try {
            $me=Http::connectTimeout(5)->timeout(10)->get('https://api.telegram.org/bot'.$token.'/getMe');
            if($me->status()===401||$me->status()===404)return ['ok'=>false,'error'=>'Telegram не принял токен. Проверьте его в @BotFather.'];
            if(!$me->successful())return ['ok'=>false,'error'=>'Telegram не ответил. Попробуйте ещё раз через минуту.'];
            $updates=Http::connectTimeout(5)->timeout(15)->get('https://api.telegram.org/bot'.$token.'/getUpdates',['allowed_updates'=>json_encode(['message','my_chat_member','channel_post'])]);
            if($updates->status()===409)return ['ok'=>false,'error'=>'У бота настроен webhook, поэтому список групп недоступен. Укажите Chat ID вручную.'];
            if(!$updates->successful())return ['ok'=>false,'error'=>'Telegram не ответил. Попробуйте ещё раз через минуту.'];
            $chats=[];
            foreach((array)$updates->json('result',[]) as $u){
                $chat=$u['my_chat_member']['chat']??$u['message']['chat']??$u['channel_post']['chat']??null;
                if(!is_array($chat)||!in_array($chat['type']??'',['group','supergroup','channel'],true))continue;
                $chats[(string)$chat['id']]=['id'=>(string)$chat['id'],'title'=>(string)($chat['title']??$chat['id']),'type'=>(string)$chat['type']];
            }
            return ['ok'=>true,'bot'=>(string)$me->json('result.username',''),'chats'=>array_values($chats)];
        }catch(\Throwable){return ['ok'=>false,'error'=>'Telegram не ответил. Попробуйте ещё раз через минуту.'];}
    }
}
