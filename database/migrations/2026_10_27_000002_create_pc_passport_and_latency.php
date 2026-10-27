<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/** PC passport (hardware inventory from iCafeCloud Boot + change log) and game-server latency. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $t) {
            $t->string('motherboard')->nullable();
            $t->string('nic')->nullable();
            $t->timestampTz('hardware_checked_at')->nullable();
        });
        Schema::create('equipment_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $t->string('field', 40);
            $t->text('old_value')->nullable();
            $t->text('new_value')->nullable();
            $t->string('source', 20);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['equipment_id', 'created_at']);
        });
        Schema::table('telemetry_hourly', fn (Blueprint $t) => $t->integer('link_min')->nullable());

        Schema::create('latency_targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('club_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('host', 255);
            $t->integer('port')->nullable();
            $t->integer('max_rtt_ms')->default(80);
            $t->integer('max_loss_pct')->default(5);
            $t->boolean('enabled')->default(true);
            $t->string('state', 10)->default('normal');
            $t->integer('bad_streak')->default(0);
            $t->integer('good_streak')->default(0);
            $t->integer('episode')->default(0);
            $t->timestampTz('last_sample_at')->nullable();
            $t->timestampsTz();
            $t->unique(['club_id', 'host', 'port']);
        });
        Schema::create('latency_samples', function (Blueprint $t) {
            $t->id();
            $t->foreignId('target_id')->constrained('latency_targets')->cascadeOnDelete();
            $t->uuid('agent_id')->nullable();
            $t->foreign('agent_id')->references('id')->on('agents')->nullOnDelete();
            $t->timestampTz('observed_at');
            $t->smallInteger('sent');
            $t->smallInteger('received');
            $t->decimal('rtt_avg_ms', 8, 2)->nullable();
            $t->decimal('rtt_max_ms', 8, 2)->nullable();
            $t->decimal('jitter_ms', 8, 2)->nullable();
            $t->index(['target_id', 'observed_at']);
        });
        DB::statement('ALTER TABLE latency_samples ADD CONSTRAINT latency_counts CHECK (sent BETWEEN 1 AND 50 AND received BETWEEN 0 AND sent)');
        DB::statement('ALTER TABLE latency_targets ADD CONSTRAINT latency_port CHECK (port IS NULL OR port BETWEEN 1 AND 65535)');
    }

    public function down(): void
    {
        Schema::dropIfExists('latency_samples');
        Schema::dropIfExists('latency_targets');
        Schema::table('telemetry_hourly', fn (Blueprint $t) => $t->dropColumn('link_min'));
        Schema::dropIfExists('equipment_changes');
        Schema::table('equipment', fn (Blueprint $t) => $t->dropColumn(['motherboard', 'nic', 'hardware_checked_at']));
    }
};
