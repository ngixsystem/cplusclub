<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training tests and exams.
 * - training_enrollments: when an employee started a program (after the intro) and when he finished it,
 *   so the time spent on the program can be shown.
 * - quiz_attempts: every chapter test and final exam attempt. Questions are fixed and shuffled at start,
 *   answers are graded on the server; correct answers never reach the browser before submission.
 * - knowledge_progress.quiz_*: best chapter test result; a chapter counts as studied only with a passed test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_enrollments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('track_id', 64);
            $t->timestampTz('started_at');
            $t->timestampTz('completed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['user_id', 'track_id']);
        });

        Schema::create('quiz_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 16); // chapter | exam
            $t->string('subject', 64); // chapter id or track id
            $t->json('questions'); // [{id, chapter, perm:[original option index per shown position]}]
            $t->json('answers')->nullable(); // {question id: shown option index}
            $t->unsignedSmallInteger('correct')->nullable();
            $t->unsignedSmallInteger('total');
            $t->unsignedSmallInteger('points')->nullable();
            $t->unsignedSmallInteger('max_points');
            $t->unsignedTinyInteger('percent')->nullable();
            $t->boolean('passed')->nullable();
            $t->timestampTz('started_at');
            $t->timestampTz('finished_at')->nullable();
            $t->timestampsTz();
            $t->index(['user_id', 'kind', 'subject']);
        });

        Schema::table('knowledge_progress', function (Blueprint $t) {
            $t->unsignedTinyInteger('quiz_best_percent')->nullable();
            $t->timestampTz('quiz_passed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_progress', fn (Blueprint $t) => $t->dropColumn(['quiz_best_percent', 'quiz_passed_at']));
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('training_enrollments');
    }
};
