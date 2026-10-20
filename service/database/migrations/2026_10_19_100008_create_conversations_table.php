<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            // `conv_` + a 26-character ULID; it is also the `sub` of the client secret.
            $table->string('id', 31)->primary();
            $table->foreignUlid('account_id')->constrained('accounts');
            // Restricted: a deleted (soft) config stays referenced by the conversations that used it.
            $table->string('avatar_config_id', 31);
            // Copied from the config at create time, so the sweeper and the poller never join for it.
            $table->string('provider', 32);
            $table->string('status', 16)->default('created');
            $table->string('language', 2);
            $table->text('system_prompt');
            $table->text('opening_text')->nullable();
            $table->unsignedInteger('max_duration_seconds');
            // The host's opaque values; never interpreted.
            $table->string('external_ref', 255)->nullable();
            // `json`, not `jsonb`: jsonb reorders the keys of the host's object.
            $table->json('metadata')->default('{}');
            $table->string('provider_session_ref', 255)->nullable();
            $table->string('provider_context_ref', 255)->nullable();
            // sha256 of the provider callback token; the token itself is never stored.
            $table->char('callback_token_hash', 64)->nullable()->unique();
            $table->string('ended_reason', 24)->nullable();
            // The provider's own reason, kept apart from the stable `ended_reason`.
            $table->string('provider_end_reason', 128)->nullable();
            $table->string('transcript_status', 16)->default('pending');
            $table->timestamp('connecting_at')->nullable();
            $table->timestamp('live_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->foreign('avatar_config_id')->references('id')->on('avatar_configs')->restrictOnDelete();
            $table->index(['account_id', 'created_at']);
            $table->index(['status', 'connecting_at']);
            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_status_check CHECK (status IN ('created', 'connecting', 'live', 'ended', 'failed'))");
        DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_ended_reason_check CHECK (ended_reason IS NULL OR ended_reason IN ('client', 'api', 'max_duration', 'provider', 'expired', 'provider_error', 'provider_capacity'))");
        DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_transcript_status_check CHECK (transcript_status IN ('pending', 'ready', 'partial'))");
        DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_metadata_check CHECK (json_typeof(metadata) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
