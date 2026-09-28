<?php
namespace App\Domain\Monitoring;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;
final class EvaluateSample {
    // Called only inside ingestion transaction with the equipment row locked.
    public function execute(int $clubId,int $equipmentId,array $sample):void {
        $when=CarbonImmutable::parse($sample['observed_at']);
        $maintenance=DB::table('maintenance_windows')->where('club_id',$clubId)->where('starts_at','<=',$when)->where('ends_at','>=',$when)->exists();
        foreach(DB::table('monitor_rules')->where('club_id',$clubId)->get() as $rule){
            DB::table('alerts')->insertOrIgnore(['club_id'=>$clubId,'equipment_id'=>$equipmentId,'metric'=>$rule->metric]);
            $alert=DB::table('alerts')->where('equipment_id',$equipmentId)->where('metric',$rule->metric)->lockForUpdate()->first();
            if($alert->last_sample_at && $when->lessThanOrEqualTo(CarbonImmutable::parse($alert->last_sample_at)))continue;
            $value=$sample[$rule->metric]??null;
            $update=['last_sample_at'=>$when];
            if($maintenance || $value===null){$update['breach_since']=null;DB::table('alerts')->where('id',$alert->id)->update($update);continue;}
            $low=$rule->metric==='disk_free_percent';
            $breach=$low?$value<=$rule->trigger_value:$value>=$rule->trigger_value;
            $recover=$low?$value>=$rule->recovery_value:$value<=$rule->recovery_value;
            $gap=$alert->last_sample_at && CarbonImmutable::parse($alert->last_sample_at)->diffInSeconds($when)>180;
            if($breach && $alert->state!=='active'){
                $since=(!$alert->breach_since||$gap)?$when:CarbonImmutable::parse($alert->breach_since);
                $update['breach_since']=$since;
                if($since->diffInSeconds($when)>=$rule->hold_seconds){
                    $update+=['state'=>'active','episode'=>$alert->episode+1,'opened_at'=>$when,'recovered_at'=>null];
                    $this->event($clubId,'monitor_problem','alert:'.$alert->id.':'.($alert->episode+1).':open',['equipment_id'=>$equipmentId,'metric'=>$rule->metric,'value'=>$value]);
                }
            }elseif($recover && $alert->state==='active'){
                $update+=['state'=>'normal','recovered_at'=>$when,'breach_since'=>null];
                $this->event($clubId,'monitor_recovered','alert:'.$alert->id.':'.$alert->episode.':recovered',['equipment_id'=>$equipmentId,'metric'=>$rule->metric,'value'=>$value]);
            }elseif(!$breach){$update['breach_since']=null;}
            DB::table('alerts')->where('id',$alert->id)->update($update);
        }
    }
    public function event(int $club,string $kind,string $key,array $payload):void {
        DB::table('outbox_events')->insertOrIgnore(['club_id'=>$club,'kind'=>$kind,'dedup_key'=>$key,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),'created_at'=>now()]);
    }
}
