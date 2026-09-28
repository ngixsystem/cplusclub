<?php
namespace App\Http\Controllers;
use App\Domain\Monitoring\EvaluateSample;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Carbon\CarbonImmutable;
class TelemetryController extends Controller {
    public function ingest(Request $r,EvaluateSample $evaluator) {
        abort_if(strlen($r->getContent())>131072,413);
        $token=$r->bearerToken();abort_unless($token && strlen($token)<=256,401);
        $agent=DB::table('agents')->where('token_hash',hash('sha256',$token))->whereNull('revoked_at')->first();abort_unless($agent,401);
        $rules=['batch_id'=>'required|uuid','samples'=>'required|array|min:1|max:100','samples.*.equipment_id'=>'required|integer|distinct','samples.*.observed_at'=>'required|date','samples.*.sensor_status'=>'required|array'];
        foreach(['cpu_temp','gpu_temp','cpu_load','gpu_load','ram_used_percent','disk_free_percent'] as $metric){
            $rules['samples.*.'.$metric]='present|nullable|numeric|between:0,'.(str_contains($metric,'temp')?'150':'100');
            $rules['samples.*.sensor_status.'.$metric]=['required',Rule::in(['ok','unavailable','unsupported','permission_denied'])];
        }
        $data=$r->validate($rules);
        $duplicate=DB::transaction(function()use($agent,$data,$evaluator){
            $locked=DB::table('agents')->where('id',$agent->id)->lockForUpdate()->first();abort_if($locked->revoked_at,401);
            if(!DB::table('telemetry_batches')->insertOrIgnore(['agent_id'=>$agent->id,'batch_id'=>$data['batch_id'],'received_at'=>now()]))return true;
            $ids=DB::table('agent_equipment')->where('agent_id',$agent->id)->pluck('equipment_id')->all();
            $rows=[];
            foreach($data['samples'] as $s){
                abort_unless(in_array($s['equipment_id'],$ids,true),403);
                $equipment=DB::table('equipment')->where('id',$s['equipment_id'])->where('club_id',$agent->club_id)->lockForUpdate()->first();abort_unless($equipment,403);
                $when=CarbonImmutable::parse($s['observed_at'])->utc();abort_if($when->isAfter(now()->addMinute())||$when->isBefore(now()->subMinutes(5)),422,'Время пакета вне допустимого окна.');
                $row=['equipment_id'=>$equipment->id,'observed_at'=>$when,'received_at'=>now()];
                foreach(['cpu_temp','gpu_temp','cpu_load','gpu_load','ram_used_percent','disk_free_percent'] as $m){
                    abort_if(($s['sensor_status'][$m]==='ok')!==($s[$m]!==null),422,'Значение и статус датчика не согласованы.');
                    $row[$m]=$s[$m];
                }
                $row['sensor_status']=json_encode($s['sensor_status'],JSON_THROW_ON_ERROR);$rows[]=$row;
                DB::table('equipment')->where('id',$equipment->id)->update(['last_seen_at'=>now()]);
                $evaluator->execute($agent->club_id,$equipment->id,$s);
            }
            DB::table('telemetry_samples')->insert($rows);
            DB::table('agents')->where('id',$agent->id)->update(['last_seen_at'=>now()]);return false;
        });return response()->json(['accepted'=>true,'duplicate'=>$duplicate]);
    }
}
