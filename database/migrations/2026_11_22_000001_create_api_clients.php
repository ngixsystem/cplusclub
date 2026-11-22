<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only API for external systems (e.g. a club owner's own dashboard): one token per
 * consumer, bound to one club and optionally to source IPs; every request is logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->foreignId('club_id')->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->string('token_hint', 8);
            $t->text('allowed_ips')->nullable();          // comma separated; empty = any
            $t->json('scopes');
            $t->timestampTz('last_used_at')->nullable();
            $t->string('last_ip', 45)->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });

        Schema::create('api_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('api_client_id')->constrained()->cascadeOnDelete();
            $t->string('method', 8);
            $t->string('path', 200);
            $t->smallInteger('status');
            $t->string('ip', 45)->nullable();
            $t->integer('ms')->default(0);
            $t->timestampTz('created_at');
            $t->index(['api_client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_requests');
        Schema::dropIfExists('api_clients');
    }
};
