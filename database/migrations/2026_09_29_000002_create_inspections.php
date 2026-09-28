<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema, DB};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $t) {
            $t->id(); $t->foreignId('club_id')->constrained()->restrictOnDelete();
            $t->foreignId('specialist_id')->constrained('users')->restrictOnDelete();
            $t->date('planned_date'); $t->string('status', 20)->default('planned');
            $t->text('report')->nullable(); $t->timestampTz('completed_at')->nullable(); $t->timestampsTz();
            $t->unique(['id','club_id']); $t->index(['club_id','planned_date']);
        });
        Schema::create('inspections', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('visit_id'); $t->foreignId('club_id')->constrained();
            $t->unsignedBigInteger('equipment_id');
            $t->foreign(['visit_id','club_id'])->references(['id','club_id'])->on('visits')->restrictOnDelete();
            $t->foreign(['equipment_id','club_id'])->references(['id','club_id'])->on('equipment')->restrictOnDelete();
            $t->string('status', 20)->default('pending'); $t->jsonb('checklist')->nullable();
            $t->string('work_type', 24)->default('inspection');
            $t->text('approval')->nullable(); $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable(); $t->text('findings')->nullable();
            $t->text('work_done')->nullable(); $t->text('verification')->nullable();
            $t->text('skip_reason')->nullable(); $t->date('rescheduled_date')->nullable();
            $t->date('completed_date')->nullable(); $t->foreignId('ticket_id')->nullable()->constrained();
            $t->timestampsTz(); $t->unique(['visit_id','equipment_id']);
        });
        DB::statement("ALTER TABLE inspections ADD CONSTRAINT inspection_status CHECK (status IN ('pending','completed','skipped'))");
        DB::statement("ALTER TABLE inspections ADD CONSTRAINT inspection_work_type CHECK (work_type IN ('inspection','cleaning','disassembly','replacement'))");
        DB::statement("ALTER TABLE inspections ADD CONSTRAINT skipped_reason CHECK (status <> 'skipped' OR (skip_reason IS NOT NULL AND length(trim(skip_reason)) > 0 AND rescheduled_date IS NOT NULL))");
        Schema::create('attachments', function (Blueprint $t) {
            $t->id(); $t->foreignId('club_id')->constrained(); $t->foreignId('uploaded_by')->constrained('users');
            $t->foreignId('ticket_id')->nullable()->constrained();
            $t->foreignId('equipment_id')->nullable()->constrained('equipment');
            $t->foreignId('inspection_id')->nullable()->constrained();
            $t->string('stage', 12)->nullable(); $t->string('path')->unique(); $t->string('name');
            $t->string('mime', 100); $t->integer('size'); $t->timestampTz('created_at')->useCurrent();
            $t->index(['club_id','ticket_id']);
        });
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachment_one_parent CHECK (num_nonnulls(ticket_id,equipment_id,inspection_id) = 1)');
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id(); $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->foreignId('club_id')->nullable()->constrained(); $t->string('action', 80);
            $t->string('subject', 100); $t->jsonb('changes')->nullable(); $t->timestampTz('created_at')->useCurrent();
            $t->index(['club_id','created_at']);
        });
    }
    public function down(): void { foreach (['audit_logs','attachments','inspections','visits'] as $t) Schema::dropIfExists($t); }
};
