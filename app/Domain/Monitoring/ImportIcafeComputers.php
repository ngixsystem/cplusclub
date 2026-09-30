<?php
namespace App\Domain\Monitoring;
use App\Domain\Icafe\Client;
use App\Models\{Club,Equipment};
use Illuminate\Support\Facades\DB;

final class ImportIcafeComputers {
    public function execute(Club $club):array {
        if(!$club->icafe_license||!$club->icafe_token)throw new \RuntimeException('Сначала подключите iCafeCloud в карточке клуба.');
        $rows=app(Client::class)->get($club->icafe_license,$club->icafe_token,'pcs');
        $rows=array_values(array_filter($rows,fn($r)=>(int)($r['pc_icafe_id']??0)===(int)$club->icafe_license && (int)($r['pc_console_type']??-1)===0));
        $names=array_column($rows,'pc_name');
        if(count($names)!==count($rows)||count(array_unique($names))!==count($rows))throw new \RuntimeException('API вернул неоднозначный список ПК. Импорт отменён.');
        return DB::transaction(function()use($club,$rows){
            $club=Club::lockForUpdate()->findOrFail($club->id);$created=0;$linked=0;$used=[];
            $existing=Equipment::where('club_id',$club->id)->where('type','pc')->lockForUpdate()->get();
            $mac=fn($s)=>strtolower(preg_replace('/[^a-fA-F0-9]/','',(string)$s));
            foreach($rows as $r){
                $name=trim((string)$r['pc_name']);if($name===''||mb_strlen($name)>255)throw new \RuntimeException('Некорректное имя ПК. Импорт отменён.');
                $target=$existing->firstWhere('icafe_pc_name',$name);
                if(!$target){
                    $candidates=$existing->filter(fn($e)=>($mac($r['pc_mac']??'')!==''&&$mac($e->mac)===$mac($r['pc_mac']))||($e->icafe_pc_name===null&&(strcasecmp($e->name,$name)===0||(string)$e->workstation_number===$name)));
                    if($candidates->count()>1)throw new \RuntimeException('Найдено несколько соответствий ПК. Устраните дубликаты оборудования.');
                    $target=$candidates->first();
                }
                if(!$target){
                    $target=Equipment::create(['club_id'=>$club->id,'name'=>$name,'type'=>'pc','zone'=>mb_substr((string)($r['pc_area_name']??''),0,255),
                        'ip'=>filter_var($r['pc_ip']??'',FILTER_VALIDATE_IP)?$r['pc_ip']:null,
                        'mac'=>strlen($mac($r['pc_mac']??''))===12?implode(':',str_split($mac($r['pc_mac']),2)):null]);
                    $existing->push($target);$created++;
                }elseif($target->icafe_pc_name===null){$linked++;}
                if(isset($used[$target->id]))throw new \RuntimeException('Несколько ПК API соответствуют одному устройству. Импорт отменён.');
                $used[$target->id]=true;$target->icafe_pc_name=$name;$target->save();
            }
            app(ClubTemplate::class)->save($club,(float)$club->monitor_warning,(float)$club->monitor_critical,(int)$club->monitor_hold_seconds);
            return ['found'=>count($rows),'created'=>$created,'linked'=>$linked];
        });
    }
}
