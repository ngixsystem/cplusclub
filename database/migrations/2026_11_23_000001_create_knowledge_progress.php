<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training progress: which checklist items of a knowledge chapter a user has confirmed.
 * A chapter counts as completed when every checklist item is checked.
 * Replaces the per-browser localStorage marks so owner/lead can see team progress.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_progress', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('article_id', 64);
            $t->json('checked');
            $t->timestampTz('completed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['user_id', 'article_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_progress');
    }
};
