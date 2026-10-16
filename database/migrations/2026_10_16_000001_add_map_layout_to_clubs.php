<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Club floor-plan layout for the 3D club map (private per club, imported by cclub:club-map).
return new class extends Migration {
    public function up(): void
    {
        Schema::table('clubs', fn (Blueprint $table) => $table->jsonb('map_layout')->nullable());
    }

    public function down(): void
    {
        Schema::table('clubs', fn (Blueprint $table) => $table->dropColumn('map_layout'));
    }
};
