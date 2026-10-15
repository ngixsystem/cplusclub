<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('connection_requests',function(Blueprint $t){$t->id();$t->uuid('request_id')->unique();$t->string('name',100);$t->string('club',150);$t->string('contact',200);$t->unsignedInteger('pc_count');$t->string('plan',30);$t->text('message')->nullable();$t->string('status',20)->default('new')->index();$t->timestampsTz();}); }
    public function down(): void { Schema::dropIfExists('connection_requests'); }
};
