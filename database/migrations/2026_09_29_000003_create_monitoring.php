<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
    public function up():void {
        Schema::create('agents',function(Blueprint $t){$t->uuid('id')->primary();$t->foreignId('club_id')->constrained();$t->string('name');$t->char('token_hash',64)->unique();$t->timestampTz('revoked_at')->nullable();$t->timestampTz('last_seen_at')->nullable();$t->timestampsTz();});
        Schema::create('agent_equipment',function(Blueprint $t){$t->uuid('agent_id');$t->foreign('agent_id')->references('id')->on('agents')->cascadeOnDelete();$t->foreignId('equipment_id')->constrained('equipment');$t->primary(['agent_id','equipment_id']);});
        Schema::create('telemetry_batches',function(Blueprint $t){$t->bigIncrements('id');$t->uuid('agent_id');$t->foreign('agent_id')->references('id')->on('agents');$t->uuid('batch_id');$t->timestampTz('received_at')->useCurrent();$t->unique(['agent_id','batch_id']);});
        Schema::create('telemetry_samples',function(Blueprint $t){
            $t->bigIncrements('id');$t->foreignId('equipment_id')->constrained('equipment');$t->timestampTz('observed_at');$t->timestampTz('received_at')->useCurrent();
            foreach(['cpu_temp','gpu_temp','cpu_load','gpu_load','ram_used_percent','disk_free_percent'] as $name)$t->decimal($name,6,2)->nullable();
            $t->jsonb('sensor_status');$t->index(['equipment_id','observed_at']);$t->index('received_at');
        });
        Schema::table('equipment',function(Blueprint $t){$t->timestampTz('last_seen_at')->nullable();$t->index(['club_id','last_seen_at']);});
        Schema::create('monitor_rules',function(Blueprint $t){$t->id();$t->foreignId('club_id')->constrained();$t->string('metric',30);$t->decimal('trigger_value',6,2);$t->decimal('recovery_value',6,2);$t->integer('hold_seconds')->default(120);$t->unique(['club_id','metric']);});
        DB::statement("ALTER TABLE monitor_rules ADD CONSTRAINT valid_rule CHECK (metric IN ('cpu_temp','gpu_temp','cpu_load','gpu_load','ram_used_percent','disk_free_percent') AND hold_seconds BETWEEN 0 AND 3600 AND ((metric='disk_free_percent' AND recovery_value>trigger_value) OR (metric<>'disk_free_percent' AND recovery_value<trigger_value)))");
        Schema::create('alerts',function(Blueprint $t){$t->id();$t->foreignId('club_id')->constrained();$t->foreignId('equipment_id')->constrained('equipment');$t->string('metric',30);$t->string('state',20)->default('normal');$t->timestampTz('breach_since')->nullable();$t->timestampTz('last_sample_at')->nullable();$t->integer('episode')->default(0);$t->timestampTz('opened_at')->nullable();$t->timestampTz('recovered_at')->nullable();$t->unique(['equipment_id','metric']);$t->index(['club_id','state']);});
        Schema::create('maintenance_windows',function(Blueprint $t){$t->id();$t->foreignId('club_id')->constrained();$t->timestampTz('starts_at');$t->timestampTz('ends_at');$t->text('reason');$t->index(['club_id','ends_at']);});
        DB::statement('ALTER TABLE maintenance_windows ADD CONSTRAINT maintenance_duration CHECK (ends_at>starts_at)');
        Schema::create('telemetry_hourly',function(Blueprint $t){$t->foreignId('equipment_id')->constrained('equipment');$t->timestampTz('hour');$t->decimal('cpu_temp_avg',6,2)->nullable();$t->decimal('gpu_temp_avg',6,2)->nullable();$t->integer('samples');$t->primary(['equipment_id','hour']);});
        Schema::create('telegram_channels',function(Blueprint $t){$t->id();$t->foreignId('club_id')->constrained();$t->string('name');$t->text('bot_token');$t->string('chat_id',80);$t->jsonb('kinds');$t->boolean('enabled')->default(true);$t->timestampsTz();});
        Schema::create('deliveries',function(Blueprint $t){$t->id();$t->foreignId('outbox_event_id')->constrained()->restrictOnDelete();$t->foreignId('telegram_channel_id')->constrained()->restrictOnDelete();$t->string('status',20)->default('pending');$t->integer('attempts')->default(0);$t->timestampTz('next_attempt_at')->nullable();$t->timestampTz('sent_at')->nullable();$t->string('message_id')->nullable();$t->unique(['outbox_event_id','telegram_channel_id']);$t->index(['status','next_attempt_at']);});
        Schema::create('delivery_attempts',function(Blueprint $t){$t->id();$t->foreignId('delivery_id')->constrained();$t->integer('number');$t->string('outcome',40);$t->integer('http_status')->nullable();$t->timestampTz('created_at')->useCurrent();});
    }
    public function down():void {foreach(['delivery_attempts','deliveries','telegram_channels','telemetry_hourly','maintenance_windows','alerts','monitor_rules','telemetry_samples','telemetry_batches','agent_equipment','agents'] as $t)Schema::dropIfExists($t);Schema::table('equipment',fn(Blueprint $t)=>$t->dropColumn('last_seen_at'));}
};
