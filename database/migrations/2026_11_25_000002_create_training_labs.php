<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cisco practice: subnet and IOS command trainer attempts, Packet Tracer lab submissions with review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_lab_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 16); // subnet | ios
            $t->string('subject', 64); // task id, or "session" for subnets
            $t->json('payload'); // subnet: tasks with answers (server only); ios: submitted commands
            $t->json('answers')->nullable();
            $t->json('result')->nullable();
            $t->unsignedTinyInteger('score')->nullable();
            $t->boolean('passed')->nullable();
            $t->timestampTz('started_at');
            $t->timestampTz('finished_at')->nullable();
            $t->timestampsTz();
            $t->index(['user_id', 'kind', 'subject']);
        });

        Schema::create('training_lab_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('lab_id', 64);
            $t->text('comment');
            $t->json('files'); // [{path, name, size}]
            $t->string('status', 16)->default('submitted'); // submitted | accepted | rework
            $t->unsignedTinyInteger('grade')->nullable();
            $t->text('review_comment')->nullable();
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('reviewed_at')->nullable();
            $t->timestampsTz();
            $t->index(['user_id', 'lab_id']);
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_lab_submissions');
        Schema::dropIfExists('training_lab_attempts');
    }
};
