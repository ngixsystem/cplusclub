<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket practice runs: a sandbox incident handled through the real ticket workflow.
 * Kept apart from tickets on purpose — practice never reaches reports, statistics, Telegram or the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_ticket_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('scenario_id', 64);
            $t->string('mode', 8); // demo | exam
            $t->string('status', 16); // message, then real ticket statuses
            $t->json('state');
            $t->unsignedTinyInteger('score')->nullable();
            $t->boolean('passed')->nullable();
            $t->timestampTz('started_at');
            $t->timestampTz('finished_at')->nullable();
            $t->timestampsTz();
            $t->index(['user_id', 'scenario_id', 'mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_ticket_runs');
    }
};
