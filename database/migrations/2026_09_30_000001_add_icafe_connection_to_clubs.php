<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->unsignedBigInteger('icafe_license')->nullable()->unique();
            $table->text('icafe_token')->nullable();
            $table->string('icafe_currency', 3)->default('UZS');
        });
    }
    public function down(): void
    {
        Schema::table('clubs', fn (Blueprint $table) => $table->dropColumn(['icafe_license', 'icafe_token', 'icafe_currency']));
    }
};
