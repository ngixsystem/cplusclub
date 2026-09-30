<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::table('equipment',function(Blueprint $t){$t->string('icafe_pc_name')->nullable();$t->unique(['club_id','icafe_pc_name']);});
        Schema::table('clubs',function(Blueprint $t){$t->decimal('monitor_warning',6,2)->default(50);$t->decimal('monitor_critical',6,2)->default(79);$t->integer('monitor_hold_seconds')->default(0);});
        Schema::table('monitor_rules',fn(Blueprint $t)=>$t->boolean('exclusive')->default(false));
    }
    public function down():void {
        Schema::table('monitor_rules',fn(Blueprint $t)=>$t->dropColumn('exclusive'));
        Schema::table('clubs',fn(Blueprint $t)=>$t->dropColumn(['monitor_warning','monitor_critical','monitor_hold_seconds']));
        Schema::table('equipment',function(Blueprint $t){$t->dropUnique(['club_id','icafe_pc_name']);$t->dropColumn('icafe_pc_name');});
    }
};
