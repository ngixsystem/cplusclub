<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Club computers reachable through the self-hosted RustDesk server, and who connected when. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_hosts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('club_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('rustdesk_id', 32)->nullable();
            $t->text('password');
            $t->string('enroll_token_hash', 64)->nullable()->unique();
            $t->timestampTz('enroll_expires_at')->nullable();
            $t->string('hostname', 120)->nullable();
            $t->timestampTz('enrolled_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index('club_id');
        });
        Schema::create('remote_connections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('remote_host_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('ip', 45)->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['remote_host_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_connections');
        Schema::dropIfExists('remote_hosts');
    }
};
