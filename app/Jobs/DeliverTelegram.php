<?php
namespace App\Jobs;
use App\Models\TelegramChannel;
use App\Domain\Notifications\TelegramTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{Cache,DB};
class DeliverTelegram implements ShouldQueue {
    use Queueable;
    public int $tries=8;
    public int $timeout=25;
    public function __construct(public int $deliveryId){$this->onQueue('notifications');}
    public function backoff():array {return [10,30,120,600,1800];}
    public function handle(TelegramTransport $transport):void {
        $lock=Cache::lock('delivery:'.$this->deliveryId,40);
        if(!$lock->get()){ $this->release(10);return; }
        try {
            $delivery=DB::table('deliveries')->find($this->deliveryId);
            if(!$delivery||in_array($delivery->status,['sent','cancelled','failed'],true))return;
            if($delivery->next_attempt_at && now()->lt($delivery->next_attempt_at)){ $this->release(max(1,(int)now()->diffInSeconds($delivery->next_attempt_at)));return; }
            $channel=TelegramChannel::find($delivery->telegram_channel_id);$event=DB::table('outbox_events')->find($delivery->outbox_event_id);
            if(!$channel||!$channel->enabled||!$event||$channel->club_id!==$event->club_id||!in_array($event->kind,$channel->kinds,true)){
                DB::table('deliveries')->where('id',$delivery->id)->update(['status'=>'cancelled']);return;
            }
            $number=$delivery->attempts+1;
            DB::table('deliveries')->where('id',$delivery->id)->update(['attempts'=>$number,'status'=>'sending','next_attempt_at'=>now()->addSeconds(60)]);
            $result=$transport->send($channel,'C+CLub · '.$event->kind.' · событие #'.$event->id."\n".$event->payload);
            $success=$result['ok'];$delay=$result['retry_after']??min(1800,10*(2**min($number,7)));
            DB::transaction(function()use($delivery,$result,$success,$number,$delay){
                DB::table('delivery_attempts')->insert(['delivery_id'=>$delivery->id,'number'=>$number,'outcome'=>$success?(config('services.telegram.transport')==='fake'?'fake_sent':'sent'):(!empty($result['ambiguous'])?'unknown_acceptance':'rejected'),'http_status'=>$result['status'],'created_at'=>now()]);
                DB::table('deliveries')->where('id',$delivery->id)->update(['status'=>$success?'sent':($number>=8?'failed':'pending'),'sent_at'=>$success?now():null,'message_id'=>$success?$result['message_id']:null,'next_attempt_at'=>$success?null:now()->addSeconds($delay)]);
            });
            if(!$success){if($number>=8)$this->fail(new \RuntimeException('Telegram delivery attempts exhausted; see safe delivery log.'));else $this->release($delay);}
        }finally{$lock->release();}
    }
}
