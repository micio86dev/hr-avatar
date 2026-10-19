<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            // `we_` + a 26-character ULID.
            $table->string('id', 31)->primary();
            $table->foreignUlid('account_id')->constrained('accounts');
            $table->string('url', 2048);
            $table->jsonb('events');
            $table->boolean('enabled')->default(true);
            $table->string('description', 255)->nullable();
            // Encrypted: it is needed to sign every delivery. Never shown after the create response.
            $table->text('secret');
            $table->timestamps();

            $table->index(['account_id', 'created_at']);
        });

        DB::statement("ALTER TABLE webhook_endpoints ADD CONSTRAINT webhook_endpoints_events_check CHECK (jsonb_typeof(events) = 'array' AND jsonb_array_length(events) > 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
