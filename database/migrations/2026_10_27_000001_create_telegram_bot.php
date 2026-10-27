<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Two-way Telegram: the bot reads its updates (buttons, /ticket messages), so it needs
 * a per-bot offset, the chats it has seen, link follow-ups and ticket source fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_channels', function (Blueprint $t) {
            $t->string('token_hash', 64)->nullable()->index();
        });
        foreach (DB::table('telegram_channels')->get(['id', 'bot_token']) as $c) {
            try { $token = decrypt($c->bot_token, false); } catch (\Throwable) { continue; }
            DB::table('telegram_channels')->where('id', $c->id)->update(['token_hash' => hash('sha256', $token)]);
        }

        Schema::create('telegram_bot_state', function (Blueprint $t) {
            $t->string('token_hash', 64)->primary();
            $t->bigInteger('update_offset')->default(0);
            $t->string('username')->nullable();
            $t->string('last_error')->nullable();
            $t->timestampTz('polled_at')->nullable();
        });

        Schema::create('telegram_seen_chats', function (Blueprint $t) {
            $t->id();
            $t->string('token_hash', 64);
            $t->string('chat_id', 80);
            $t->string('title');
            $t->string('type', 20);
            $t->timestampTz('seen_at');
            $t->unique(['token_hash', 'chat_id']);
        });

        Schema::table('tickets', function (Blueprint $t) {
            $t->string('source', 20)->nullable();
            $t->foreignId('source_channel_id')->nullable()->constrained('telegram_channels')->nullOnDelete();
            $t->string('source_message_id', 40)->nullable();
            $t->string('bot_message_id', 40)->nullable();
            $t->string('reporter_name', 120)->nullable();
            $t->smallInteger('rating')->nullable();
            $t->string('rated_by', 120)->nullable();
            $t->timestampTz('rated_at')->nullable();
        });
        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_rating_check CHECK (rating IS NULL OR rating BETWEEN 1 AND 5)');

        Schema::create('link_followups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $t->integer('episode');
            $t->foreignId('club_id')->constrained()->cascadeOnDelete();
            $t->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $t->string('place');
            $t->integer('link_mbps');
            $t->integer('expected_mbps');
            $t->string('state', 20)->default('waiting');
            $t->foreignId('channel_id')->nullable()->constrained('telegram_channels')->nullOnDelete();
            $t->string('responder', 120)->nullable();
            $t->timestampTz('responded_at')->nullable();
            $t->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['alert_id', 'episode']);
            $t->index(['state', 'created_at']);
        });
        DB::statement("ALTER TABLE link_followups ADD CONSTRAINT link_followups_state_check CHECK (state IN ('waiting','reconnected','recovered','ticketed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('link_followups');
        DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_rating_check');
        Schema::table('tickets', function (Blueprint $t) {
            $t->dropConstrainedForeignId('source_channel_id');
            $t->dropColumn(['source', 'source_message_id', 'bot_message_id', 'reporter_name', 'rating', 'rated_by', 'rated_at']);
        });
        Schema::dropIfExists('telegram_seen_chats');
        Schema::dropIfExists('telegram_bot_state');
        Schema::table('telegram_channels', fn (Blueprint $t) => $t->dropColumn('token_hash'));
    }
};
