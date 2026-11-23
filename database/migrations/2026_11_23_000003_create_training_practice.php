<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket practice for engineers: simulated incidents worked through the real ticket workflow.
 * - clubs.is_training: the hidden training club that holds practice tickets and demo PCs. It is
 *   excluded from every club, equipment and ticket list, report, digest and Telegram flow.
 * - tickets.training_user_id: a practice ticket is visible only to the trainee who started it.
 * - training_runs: one attempt at a scenario — diagnostic actions taken, client rework, score.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', fn (Blueprint $t) => $t->boolean('is_training')->default(false)->index());
        Schema::table('tickets', function (Blueprint $t) {
            $t->foreignId('training_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $t->index('training_user_id');
        });
        Schema::create('training_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('scenario_id', 64);
            $t->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $t->string('status', 16)->default('active'); // active | finished
            $t->json('checks'); // [{id, at}]
            $t->unsignedTinyInteger('reworks')->default(0);
            $t->boolean('unneeded_proposal')->default(false);
            $t->unsignedSmallInteger('score')->nullable();
            $t->unsignedSmallInteger('max_score')->default(100);
            $t->boolean('passed')->nullable();
            $t->json('breakdown')->nullable();
            $t->timestampTz('started_at');
            $t->timestampTz('finished_at')->nullable();
            $t->timestampsTz();
            $t->index(['user_id', 'scenario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_runs');
        Schema::table('tickets', function (Blueprint $t) {
            $t->dropConstrainedForeignId('training_user_id');
        });
        Schema::table('clubs', fn (Blueprint $t) => $t->dropColumn('is_training'));
    }
};
