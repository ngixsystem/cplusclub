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
}
