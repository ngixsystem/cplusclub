<?php
namespace App\Domain\Updates;
use App\Domain\Monitoring\EvaluateSample;
use Illuminate\Support\Facades\{DB,Cache};
class CheckPublished {
    public function execute(int $appId,VersionProvider $provider):void {
        $lock=Cache::lock('published:'.$appId,60);if(!$lock->get())return;
        try {
            DB::table('published_versions')->insertOrIgnore(['app_id'=>$appId]);
            try{$data=$provider->fetch($appId);}catch(\Throwable){
                $row=DB::table('published_versions')->where('app_id',$appId)->first();$since=$row->failed_since??now();
                DB::transaction(function()use($appId,$since){
                    DB::table('published_versions')->where('app_id',$appId)->update(['source_status'=>'unavailable','failed_since'=>$since,'candidate'=>null,'confirmations'=>0]);
                    if(\Carbon\CarbonImmutable::parse($since)->diffInHours(now())>=1)foreach(DB::table('game_subscriptions')->where('app_id',$appId)->pluck('club_id') as $club)app(EvaluateSample::class)->event($club,'source_unavailable','source:'.$appId.':'.$club.':'.(string)$since,['app_id'=>$appId]);
                });return;
            }
            DB::transaction(function()use($appId,$data){
                $row=DB::table('published_versions')->where('app_id',$appId)->lockForUpdate()->first();
                $update=['checked_at'=>now(),'source_status'=>'ok','failed_since'=>null];
                if($row->build_id===null){$update+=['build_id'=>$data['build_id'],'published_at'=>$data['published_at']];}
                elseif($row->build_id!==$data['build_id']){
                    $count=$row->candidate===$data['build_id']?$row->confirmations+1:1;
                    $update+=['candidate'=>$data['build_id'],'confirmations'=>$count];
                    if($count>=2){
                        $update=array_merge($update,['build_id'=>$data['build_id'],'published_at'=>$data['published_at'],'candidate'=>null,'confirmations'=>0,'revision'=>$row->revision+1]);
                        foreach(DB::table('game_subscriptions')->where('app_id',$appId)->pluck('club_id') as $club)app(EvaluateSample::class)->event($club,'published_build','published:'.$appId.':'.$club.':'.($row->revision+1),['app_id'=>$appId,'old'=>$row->build_id,'new'=>$data['build_id'],'source'=>$row->source]);
                    }
                }else{$update+=['candidate'=>null,'confirmations'=>0];}
                DB::table('published_versions')->where('app_id',$appId)->update($update);
            });
        }finally{$lock->release();}
    }
}
