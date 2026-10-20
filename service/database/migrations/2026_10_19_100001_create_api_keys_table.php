<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained('accounts');
            $table->string('name')->nullable();
            $table->string('type', 16);
            $table->string('mode', 8);
            // Type/mode marker plus the first 8 hex characters: identifies a key, never authenticates one.
            $table->string('key_prefix', 32)->index();
            $table->char('key_hash', 64)->unique();
            $table->jsonb('scopes')->default('[]');
            $table->jsonb('allowed_origins')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->ulid('rotated_from_id')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'created_at']);
        });

        Schema::table('api_keys', function (Blueprint $table) {
            $table->foreign('rotated_from_id')->references('id')->on('api_keys')->nullOnDelete();
        });

        DB::statement("ALTER TABLE api_keys ADD CONSTRAINT api_keys_type_check CHECK (type IN ('secret', 'publishable'))");
        DB::statement("ALTER TABLE api_keys ADD CONSTRAINT api_keys_mode_check CHECK (mode IN ('live', 'test'))");
        // A secret key carries scopes and no origins; a publishable key carries 1-20 origins and no scopes.
        DB::statement(<<<'SQL'
            ALTER TABLE api_keys ADD CONSTRAINT api_keys_type_shape_check CHECK (
                (type = 'secret' AND allowed_origins IS NULL)
                OR (type = 'publishable'
                    AND scopes = '[]'::jsonb
                    AND allowed_origins IS NOT NULL
                    AND jsonb_typeof(allowed_origins) = 'array'
                    AND jsonb_array_length(allowed_origins) BETWEEN 1 AND 20)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
