<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Online status of remote hosts, polled from the RustDesk server once a minute. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_hosts', function (Blueprint $t) {
            $t->boolean('online')->nullable();
            $t->timestampTz('last_seen_at')->nullable();
            $t->timestampTz('status_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('remote_hosts', fn (Blueprint $t) => $t->dropColumn(['online', 'last_seen_at', 'status_changed_at']));
    }
};
