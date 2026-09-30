<?php
namespace App\Domain\Monitoring;
use App\Domain\Icafe\Client;
use App\Models\{Club,Equipment};
use Illuminate\Support\Facades\{Cache,DB};
use Carbon\CarbonImmutable;

final class LiveComputers {
    public static function severity(?float $cpu,?float $gpu,float $warning=50,float $critical=79):string {
        $values=array_filter([$cpu,$gpu],fn($v)=>$v!==null);
        if(!$values)return 'unknown';
        if(max($values)>$critical)return 'critical';
        if(max($values)>=$warning)return 'warning';
        return count($values)===2?'normal':'unknown';
    }
    private function connectivity(Club $club):array {
        if(!$club->icafe_license||!$club->icafe_token)return ['stale'=>true,'updated_at'=>null,'online'=>[],'error'=>'iCafeCloud не подключён.'];
        $key='icafe:online:'.$club->id.':'.hash('sha256',$club->icafe_token.'|'.$club->icafe_license);
        $old=Cache::get($key);
        if($old && time()-$old['checked_at']<30)return $old;
        $lock=Cache::lock($key.':lock',25);
        if(!$lock->get())return $old??['stale'=>true,'updated_at'=>null,'online'=>[],'error'=>'Получаем онлайн-статусы…'];
        try {
            $rows=app(Client::class)->get($club->icafe_license,$club->icafe_token,'onlinePcList');$online=[];
            foreach($rows as $r){if(!isset($r['pc_name'],$r['is_connected']))throw new \RuntimeException();if((int)$r['is_connected']===1)$online[]=(string)$r['pc_name'];}
            $data=['stale'=>false,'updated_at'=>now()->toIso8601String(),'online'=>$online,'error'=>null,'checked_at'=>time()];
        }catch(\Throwable){$data=[...($old??['online'=>[],'updated_at'=>null]),'stale'=>true,'error'=>'Нет свежей связи с iCafeCloud.','checked_at'=>time()];}
        finally{$lock->release();}
        Cache::put($key,$data,600);return $data;
    }
    public function snapshot(Club $club):array {
        $connection=$this->connectivity($club);
        $equipment=Equipment::where('club_id',$club->id)->where('type','pc')->orderBy('name')->get();
        $latest=DB::table('telemetry_samples')->whereIn('equipment_id',$equipment->pluck('id'))
            ->where('observed_at','>=',now()->subSeconds(180))->where('observed_at','<=',now()->addMinute())
            ->selectRaw('DISTINCT ON (equipment_id) equipment_id, cpu_temp, gpu_temp, observed_at, sensor_status')
            ->orderBy('equipment_id')->orderByDesc('observed_at')->orderByDesc('id')->get()->keyBy('equipment_id');
        $pcs=$equipment->map(function($e)use($connection,$latest,$club){
            $online=$e->icafe_pc_name!==null&&!$connection['stale']?in_array($e->icafe_pc_name,$connection['online'],true):null;
            $sample=$latest->get($e->id);$when=$sample?CarbonImmutable::parse($sample->observed_at):null;
            $fresh=$when && $when->gte(now()->subSeconds(180)) && $when->lte(now()->addMinute());
            $status=$sample?json_decode($sample->sensor_status,true):[];
            $cpu=$fresh && ($status['cpu_temp']??null)==='ok' && $sample->cpu_temp!==null && $online!==false?(float)$sample->cpu_temp:null;
            $gpu=$fresh && ($status['gpu_temp']??null)==='ok' && $sample->gpu_temp!==null && $online!==false?(float)$sample->gpu_temp:null;
            return ['id'=>$e->id,'name'=>$e->name,'zone'=>$e->zone,'imported'=>$e->icafe_pc_name!==null,'online'=>$online,
                'cpu_temp'=>$cpu,'gpu_temp'=>$gpu,'observed_at'=>$sample?->observed_at,
                'severity'=>$online===false?'offline':self::severity($cpu,$gpu,(float)$club->monitor_warning,(float)$club->monitor_critical)];
        });
        return ['pcs'=>$pcs,'alerts'=>DB::table('alerts')->where('club_id',$club->id)->where('state','active')->orderByDesc('opened_at')->limit(200)->get(['id','equipment_id','metric','opened_at']),
            'updated_at'=>now()->toIso8601String(),'connection_updated_at'=>$connection['updated_at'],'error'=>$connection['error'],
            'template'=>['warning'=>(float)$club->monitor_warning,'critical'=>(float)$club->monitor_critical,'hold_seconds'=>(int)$club->monitor_hold_seconds]];
    }
}
