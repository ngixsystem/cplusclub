<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('telemetry_samples', fn (Blueprint $t) => $t->integer('link_mbps')->nullable());
        // Highest negotiated speed seen for the PC (at least 1000): a lower link is a degradation.
        Schema::table('equipment', fn (Blueprint $t) => $t->integer('link_expected_mbps')->nullable());
    }

    public function down(): void
    {
        Schema::table('telemetry_samples', fn (Blueprint $t) => $t->dropColumn('link_mbps'));
        Schema::table('equipment', fn (Blueprint $t) => $t->dropColumn('link_expected_mbps'));
    }
};
