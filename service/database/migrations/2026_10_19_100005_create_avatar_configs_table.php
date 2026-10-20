<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avatar_configs', function (Blueprint $table) {
            // `avc_` + a 26-character ULID; 31 leaves room for the longest prefix in use.
            $table->string('id', 31)->primary();
            $table->foreignUlid('account_id')->constrained('accounts');
            $table->string('name', 120);
            // Validated against the provider registry at the HTTP boundary, so no provider name lands here.
            $table->string('provider', 32);
            $table->jsonb('config')->default('{}');
            $table->jsonb('persona')->nullable();
            $table->string('llm_model_id', 64)->nullable();
            $table->jsonb('voice')->nullable();
            // The host's opaque reference; never interpreted.
            $table->string('external_ref', 255)->nullable();
            $table->string('sync_status', 16)->default('not_required');
            // Redacted: a short message, never a provider body or a key.
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            // Owned by the provider adapter (for example created object ids).
            $table->jsonb('provider_state')->default('{}');
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('llm_model_id')->references('id')->on('llm_models')->restrictOnDelete();
            $table->index(['account_id', 'created_at']);
            $table->index(['account_id', 'external_ref']);
        });

        DB::statement("ALTER TABLE avatar_configs ADD CONSTRAINT avatar_configs_sync_status_check CHECK (sync_status IN ('not_required', 'pending', 'synced', 'failed'))");
        DB::statement(<<<'SQL'
            ALTER TABLE avatar_configs ADD CONSTRAINT avatar_configs_json_shape_check CHECK (
                jsonb_typeof(config) = 'object'
                AND jsonb_typeof(provider_state) = 'object'
                AND (persona IS NULL OR jsonb_typeof(persona) = 'object')
                AND (voice IS NULL OR jsonb_typeof(voice) = 'object')
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('avatar_configs');
    }
};
