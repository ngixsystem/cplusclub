<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A small program inside the club network that polls the switches and reports here over HTTPS.
        Schema::create('snmp_collectors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('club_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->char('token_hash', 64)->unique();
            $t->string('token_hint', 8);
            $t->string('hostname', 120)->nullable();
            $t->string('version', 20)->nullable();
            $t->string('last_ip', 45)->nullable();
            $t->timestampTz('last_seen_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->timestampsTz();
        });
        // SNMP settings of an equipment item of type "switch".
        Schema::create('switch_snmp', function (Blueprint $t) {
            $t->foreignId('equipment_id')->primary()->constrained('equipment')->cascadeOnDelete();
            $t->string('host', 255);
            $t->unsignedInteger('port')->default(161);
            $t->string('version', 4)->default('2c');
            $t->text('community');
            $t->string('sys_descr', 500)->nullable();
            $t->unsignedBigInteger('uptime_seconds')->nullable();
            $t->timestampTz('polled_at')->nullable();
            $t->string('last_error', 500)->nullable();
            $t->timestampsTz();
        });
        Schema::create('switch_ports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('switch_id')->constrained('equipment')->cascadeOnDelete();
            $t->unsignedInteger('if_index');
            $t->unsignedInteger('port_no')->nullable();
            $t->string('name', 80);
            $t->string('alias', 255)->nullable();
            $t->boolean('up')->default(false);
            $t->unsignedInteger('speed_mbps')->nullable();
            $t->unsignedInteger('mac_count')->default(0);
            $t->boolean('uplink')->default(false);
            $t->timestampTz('changed_at')->nullable();
            $t->timestampTz('updated_at')->nullable();
            $t->unique(['switch_id', 'if_index']);
        });
        // Where a PC's cable ends: the last switch port its MAC was learned on (kept while the PC is off).
        Schema::create('equipment_ports', function (Blueprint $t) {
            $t->foreignId('equipment_id')->primary()->constrained('equipment')->cascadeOnDelete();
            $t->foreignId('switch_id')->constrained('equipment')->cascadeOnDelete();
            $t->unsignedInteger('if_index');
            $t->timestampTz('seen_at');
            $t->index(['switch_id', 'if_index']);
        });
    }

    public function down(): void
    {
        foreach (['equipment_ports', 'switch_ports', 'switch_snmp', 'snmp_collectors'] as $t) Schema::dropIfExists($t);
    }
};
