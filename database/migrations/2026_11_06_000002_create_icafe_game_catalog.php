<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The club's own game list from iCafeCloud (names, exe files, all-time launch counter) and launches per day. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('icafe_games', function (Blueprint $t) {
            $t->foreignId('club_id')->constrained()->cascadeOnDelete();
            $t->bigInteger('pkg_id');
            $t->string('name', 120);
            $t->string('class', 60)->nullable();
            $t->text('exe_files')->nullable();
            $t->integer('launches')->default(0);
            $t->timestampTz('synced_at');
            $t->primary(['club_id', 'pkg_id']);
        });
        Schema::create('icafe_game_launches_daily', function (Blueprint $t) {
            $t->foreignId('club_id')->constrained()->cascadeOnDelete();
            $t->date('day');
            $t->bigInteger('pkg_id');
            $t->string('name', 120);
            $t->integer('launches')->default(0);
            $t->primary(['club_id', 'day', 'pkg_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('icafe_game_launches_daily');
        Schema::dropIfExists('icafe_games');
    }
};
