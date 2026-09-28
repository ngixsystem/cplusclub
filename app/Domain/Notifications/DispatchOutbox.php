<?php
namespace App\Domain\Notifications;
use App\Jobs\DeliverTelegram;
use App\Models\TelegramChannel;
use Illuminate\Support\Facades\DB;
class DispatchOutbox {
    public function execute():void {
        DB::transaction(function(){
            $events=DB::table('outbox_events')->whereNull('dispatched_at')->orderBy('id')->limit(200)->lock('FOR UPDATE SKIP LOCKED')->get();
            foreach($events as $event){
                foreach(TelegramChannel::where('club_id',$event->club_id)->where('enabled',true)->get() as $channel){
                    if(!in_array($event->kind,$channel->kinds,true))continue;
                    DB::table('deliveries')->insertOrIgnore(['outbox_event_id'=>$event->id,'telegram_channel_id'=>$channel->id]);
                }
                DB::table('outbox_events')->where('id',$event->id)->update(['dispatched_at'=>now()]);
            }
        });
        // Durable delivery rows are scanned again after a crash between commit and queue dispatch.
        DB::table('deliveries')->whereIn('status',['pending','sending'])->where(fn($q)=>$q->whereNull('next_attempt_at')->orWhere('next_attempt_at','<=',now()))->orderBy('id')->limit(200)->pluck('id')->each(fn($id)=>DeliverTelegram::dispatch($id));
    }
}
