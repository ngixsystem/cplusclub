<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A program assigned to an employee by the owner or a lead, with a deadline.
        Schema::create('training_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('track_id', 64);
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->date('due_on');
            $t->string('note', 500)->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'track_id']);
            $t->index('due_on');
        });
        // A private chat with one of the connected bots, linked by the employee with /start <code>.
        Schema::create('telegram_user_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64);
            $t->string('chat_id', 32);
            $t->string('username', 64)->nullable();
            $t->timestamp('linked_at');
            $t->index(['token_hash', 'chat_id']);
        });
        // One row per reminder sent; the key makes the daily sweep idempotent.
        Schema::create('training_reminders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 20);
            $t->string('key', 160)->unique();
            $t->boolean('delivered')->default(false);
            $t->timestamp('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_reminders');
        Schema::dropIfExists('telegram_user_links');
        Schema::dropIfExists('training_assignments');
    }
};
