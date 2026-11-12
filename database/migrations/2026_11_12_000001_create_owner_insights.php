<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * iCafeCloud shifts kept locally (for the morning digest and cash control baselines)
 * and periodic hall occupancy samples (for the load heatmap).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('icafe_shifts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('club_id')->constrained()->cascadeOnDelete();
            $t->string('shift_id', 40);
            $t->string('operator', 120);
            $t->timestampTz('started_at');
            $t->timestampTz('ended_at')->nullable();
            $t->decimal('total', 14, 2)->default(0);
            $t->decimal('cash', 14, 2)->default(0);
            $t->decimal('card', 14, 2)->default(0);
            $t->decimal('qr', 14, 2)->default(0);
            $t->decimal('refund', 14, 2)->nullable();
            $t->timestampTz('checked_at')->nullable();       // cash control evaluated (closed shifts)
            $t->timestampTz('long_open_alerted_at')->nullable();
            $t->timestampTz('synced_at');
            $t->unique(['club_id', 'shift_id']);
            $t->index(['club_id', 'started_at']);
        });

        Schema::create('hall_occupancy', function (Blueprint $t) {
            $t->foreignId('club_id')->constrained()->cascadeOnDelete();
            $t->timestampTz('sampled_at');
            $t->smallInteger('busy');
            $t->smallInteger('online');
            $t->smallInteger('total');
            $t->primary(['club_id', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hall_occupancy');
        Schema::dropIfExists('icafe_shifts');
    }
};
