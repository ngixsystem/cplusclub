<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_snmp', function (Blueprint $t) {
            $t->string('model', 120)->nullable()->after('sys_descr');
            $t->string('firmware', 120)->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('switch_snmp', fn (Blueprint $t) => $t->dropColumn(['model', 'firmware']));
    }
};
