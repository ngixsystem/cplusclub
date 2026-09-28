<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role', 24)->default('representative');
            $t->boolean('active')->default(true);
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('owner','lead','specialist','representative'))");
        Schema::create('clubs', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->text('address');
            $t->string('timezone')->default('Asia/Tashkent');
            $t->text('contacts')->nullable();
            $t->foreignId('specialist_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('support_hours')->default('Пн–Пт 09:00–18:00');
            $t->string('status')->default('active');
            $t->text('notes')->nullable();
            $t->timestampsTz();
        });
        Schema::create('club_user', function (Blueprint $t) {
            $t->foreignId('club_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['club_id', 'user_id']);
        });
        Schema::create('equipment', function (Blueprint $t) {
            $t->id();
            $t->foreignId('club_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('zone')->nullable();
            $t->string('workstation_number')->nullable();
            $t->string('type', 24)->default('pc');
            $t->string('cpu')->nullable();
            $t->string('gpu')->nullable();
            $t->unsignedInteger('ram_mb')->nullable();
            $t->text('disks')->nullable();
            $t->string('serial_number')->nullable();
            $t->string('inventory_number')->nullable();
            $t->ipAddress('ip')->nullable();
            $t->macAddress('mac')->nullable();
            $t->string('status', 24)->default('active');
            $t->date('last_inspection_date')->nullable();
            $t->date('next_inspection_date')->nullable();
            $t->timestampsTz();
            $t->unique(['club_id', 'workstation_number']);
            $t->unique(['id', 'club_id']);
            $t->index(['club_id', 'status', 'next_inspection_date']);
        });
        DB::statement("ALTER TABLE equipment ADD CONSTRAINT equipment_type_check CHECK (type IN ('pc','server','switch','router','ups','peripheral'))");
        Schema::create('tickets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('club_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('equipment_id')->nullable();
            $t->foreign(['equipment_id','club_id'])->references(['id','club_id'])->on('equipment')->restrictOnDelete();
            $t->string('category', 80);
            $t->text('description');
            $t->string('priority', 24)->default('normal');
            $t->foreignId('initiator_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('status', 24)->default('new');
            $t->integer('version')->default(1);
            $t->text('work_result')->nullable();
            $t->text('verification_result')->nullable();
            $t->text('cause')->nullable();
            $t->text('solution')->nullable();
            $t->timestampTz('first_responded_at')->nullable();
            $t->timestampTz('resolved_at')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['id', 'club_id']);
            $t->index(['club_id', 'status', 'created_at']);
            $t->index(['assignee_id', 'status']);
        });
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('new','accepted','working','approval','parts','resolved','closed'))");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_priority_check CHECK (priority IN ('low','normal','high','critical'))");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_closed_check CHECK (status <> 'closed' OR (length(trim(work_result)) > 0 AND work_result IS NOT NULL AND length(trim(verification_result)) > 0 AND verification_result IS NOT NULL AND length(trim(cause)) > 0 AND cause IS NOT NULL AND length(trim(solution)) > 0 AND solution IS NOT NULL))");
        Schema::create('ticket_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('from_status');
            $t->string('to_status');
            $t->text('comment')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['ticket_id', 'created_at']);
        });
        Schema::create('work_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->integer('minutes');
            $t->bigInteger('cost_minor')->default(0);
            $t->char('currency', 3)->default('UZS');
            $t->text('actions');
            $t->text('consumables')->nullable();
            $t->timestampTz('created_at')->useCurrent();
        });
        DB::statement('ALTER TABLE work_logs ADD CONSTRAINT work_logs_nonnegative CHECK (minutes > 0 AND cost_minor >= 0)');
        Schema::create('outbox_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('club_id')->constrained()->restrictOnDelete();
            $t->string('kind', 80);
            $t->string('dedup_key')->unique();
            $t->jsonb('payload');
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('dispatched_at')->nullable();
            $t->index(['dispatched_at', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['outbox_events','work_logs','ticket_events','tickets','equipment','club_user','clubs'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role','active']));
    }
};
