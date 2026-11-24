<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Video lessons: seconds of the lesson actually played (credited from player position reports,
 * seeking and faster-than-real-time jumps are not credited) and when the lesson counted as watched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_progress', function (Blueprint $t) {
            $t->unsignedInteger('watch_seconds')->default(0);
            $t->unsignedInteger('watch_duration')->nullable();
            $t->unsignedInteger('watch_position')->nullable();
            $t->timestampTz('watch_ping_at')->nullable();
            $t->timestampTz('watched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_progress', fn (Blueprint $t) => $t->dropColumn(['watch_seconds', 'watch_duration', 'watch_position', 'watch_ping_at', 'watched_at']));
    }
};
