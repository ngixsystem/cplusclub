<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::create('published_versions',function(Blueprint $t){$t->integer('app_id')->primary();$t->string('branch')->default('public');$t->string('source')->default('steamcmd.net (third-party)');$t->string('build_id')->nullable();$t->string('candidate')->nullable();$t->integer('confirmations')->default(0);$t->integer('revision')->default(0);$t->timestampTz('checked_at')->nullable();$t->timestampTz('published_at')->nullable();$t->timestampTz('failed_since')->nullable();$t->string('source_status')->default('unconfigured');});
        Schema::create('game_subscriptions',function(Blueprint $t){$t->foreignId('club_id')->constrained();$t->integer('app_id');$t->primary(['club_id','app_id']);});
        Schema::create('local_versions',function(Blueprint $t){$t->id();$t->foreignId('equipment_id')->constrained('equipment');$t->string('product',30);$t->string('value',128);$t->string('candidate',128)->nullable();$t->integer('confirmations')->default(0);$t->integer('revision')->default(0);$t->timestampTz('observed_at');$t->unique(['equipment_id','product']);});
        Schema::create('version_checks',function(Blueprint $t){$t->id();$t->foreignId('equipment_id')->constrained('equipment');$t->foreignId('user_id')->constrained();$t->string('product',30);$t->string('checked_value',128);$t->text('result');$t->timestampTz('checked_at')->useCurrent();});
    }
    public function down():void {foreach(['version_checks','local_versions','game_subscriptions','published_versions'] as $t)Schema::dropIfExists($t);}
};
