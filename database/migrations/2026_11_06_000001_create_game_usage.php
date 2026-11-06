<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which game each PC is running (iCafeCloud Boot «activeprocess») and minutes played per game per hour. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telemetry_samples', fn (Blueprint $t) => $t->string('active_process', 120)->nullable());
        Schema::create('game_usage_hourly', function (Blueprint $t) {
            $t->foreignId('club_id')->constrained()->cascadeOnDelete();
            $t->timestampTz('hour');
            $t->string('game', 80);
            $t->integer('minutes')->default(0);
            $t->primary(['club_id', 'hour', 'game']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_usage_hourly');
        Schema::table('telemetry_samples', fn (Blueprint $t) => $t->dropColumn('active_process'));
    }
};
