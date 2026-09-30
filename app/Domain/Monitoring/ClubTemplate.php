<?php
namespace App\Domain\Monitoring;
use App\Models\Club;
use Illuminate\Support\Facades\DB;

final class ClubTemplate {
    public function save(Club $club,float $warning,float $critical,int $hold):void {
        DB::transaction(function()use($club,$warning,$critical,$hold){
            $club=Club::lockForUpdate()->findOrFail($club->id);
            $club->monitor_warning=$warning;$club->monitor_critical=$critical;$club->monitor_hold_seconds=$hold;$club->save();
            foreach(['cpu_temp','gpu_temp'] as $metric)DB::table('monitor_rules')->updateOrInsert(['club_id'=>$club->id,'metric'=>$metric],
                ['trigger_value'=>$critical,'recovery_value'=>$warning-0.01,'hold_seconds'=>$hold,'exclusive'=>true]);
        });
    }
}
