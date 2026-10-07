<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ticket_proposals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->text('description');
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('expected_duration', 255);
            $t->string('status', 24)->default('pending');
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('decision_comment')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['ticket_id', 'id']);
        });
    }

    public function down(): void { Schema::dropIfExists('ticket_proposals'); }
};
