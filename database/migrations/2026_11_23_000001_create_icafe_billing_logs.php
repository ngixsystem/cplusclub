<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw iCafeCloud billing log events (same fields as its CSV export), kept locally:
 * iCafeCloud itself only keeps about six weeks. `id` is the external API cursor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('icafe_billing_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('club_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('log_id');
            $t->timestampTz('occurred_at');
            $t->string('event', 40);
            $t->string('member_account', 120)->nullable();
            $t->string('pc_name', 60)->nullable();
            $t->decimal('money', 14, 2)->default(0);
            $t->decimal('spend', 14, 2)->default(0);
            $t->decimal('card', 14, 2)->default(0);
            $t->decimal('bonus', 14, 2)->default(0);
            $t->decimal('coin', 14, 2)->default(0);
            $t->integer('used_seconds')->nullable();
            $t->text('details')->nullable();
            $t->string('staff_name', 120)->nullable();
            $t->string('package', 160)->nullable();
            $t->timestampTz('created_at');
            $t->unique(['club_id', 'log_id']);
            $t->index(['club_id', 'id']);
            $t->index(['club_id', 'occurred_at']);
        });

        Schema::create('icafe_billing_sync', function (Blueprint $t) {
            $t->foreignId('club_id')->primary()->constrained()->cascadeOnDelete();
            $t->date('backfill_day')->nullable();          // local day being loaded (going back in time)
            $t->integer('backfill_page')->default(1);
            $t->smallInteger('empty_days')->default(0);
            $t->date('oldest_day')->nullable();            // oldest local day with events
            $t->timestampTz('backfill_done_at')->nullable();
            $t->timestampTz('live_at')->nullable();
            $t->string('last_error', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('icafe_billing_sync');
        Schema::dropIfExists('icafe_billing_logs');
    }
};
