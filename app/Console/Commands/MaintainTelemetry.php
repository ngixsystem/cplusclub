<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class MaintainTelemetry extends Command {
    protected $signature='cclub:telemetry-maintain';
    protected $description='Aggregate telemetry and delete expired rows in bounded batches';
    public function handle():int {
        DB::statement("INSERT INTO telemetry_hourly (equipment_id,hour,cpu_temp_avg,gpu_temp_avg,samples) SELECT equipment_id,date_trunc('hour',observed_at),avg(cpu_temp),avg(gpu_temp),count(*) FROM telemetry_samples WHERE observed_at >= now()-interval '2 hours' AND observed_at < date_trunc('hour',now()) GROUP BY equipment_id,date_trunc('hour',observed_at) ON CONFLICT (equipment_id,hour) DO UPDATE SET cpu_temp_avg=EXCLUDED.cpu_temp_avg,gpu_temp_avg=EXCLUDED.gpu_temp_avg,samples=EXCLUDED.samples");
        // Raw: 14 days; duplicate receipts: 30 days; hourly series: 365 days.
        foreach([['telemetry_samples','received_at',14],['telemetry_batches','received_at',30]] as [$table,$column,$days]){
            for($batch=0;$batch<20;$batch++){$ids=DB::table($table)->where($column,'<',now()->subDays($days))->orderBy('id')->limit(5000)->pluck('id');if($ids->isEmpty())break;DB::table($table)->whereIn('id',$ids)->delete();}
        }
        DB::statement("DELETE FROM telemetry_hourly WHERE ctid IN (SELECT ctid FROM telemetry_hourly WHERE hour < now()-interval '365 days' LIMIT 10000)");
        return self::SUCCESS;
    }
}
