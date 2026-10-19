<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_webhook_receipts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // From the conversation the event was authenticated against, never from the payload.
            $table->foreignUlid('account_id')->constrained('accounts');
            $table->string('conversation_id', 31);
            $table->string('provider', 32);
            $table->string('provider_ref', 255);
            // Tavus: sha256(conversation_id|event_type|timestamp). Polled: `{prefix}:{session}:ended`.
            $table->string('idempotency_key', 255);
            $table->string('kind', 24);
            $table->timestamp('occurred_at', 6);
            // The provider's own words for an end; kept apart from the service's stable `ended_reason`.
            $table->string('provider_end_reason', 128)->nullable();
            // What the provider sent. May hold a transcript: hidden, and returned by no endpoint.
            $table->jsonb('payload');
            $table->timestamp('processed_at', 6)->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            // The first layer of idempotency: a delivery that was already stored inserts nothing. The account is part
            // of the key, so one tenant's event can never be dropped because another tenant stored the same key.
            $table->unique(['account_id', 'provider', 'idempotency_key']);
            $table->index(['conversation_id', 'created_at']);
        });

        DB::statement("ALTER TABLE provider_webhook_receipts ADD CONSTRAINT provider_webhook_receipts_kind_check CHECK (kind IN ('joined', 'ended', 'transcript_ready', 'ignored'))");
        DB::statement("ALTER TABLE provider_webhook_receipts ADD CONSTRAINT provider_webhook_receipts_payload_check CHECK (jsonb_typeof(payload) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_webhook_receipts');
    }
};
