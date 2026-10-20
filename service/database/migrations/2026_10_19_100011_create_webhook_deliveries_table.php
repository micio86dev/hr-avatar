<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained('accounts');
            // Deleting an endpoint (or, one day, a conversation) takes its deliveries with it, so pending retries find no row and stop.
            $table->string('webhook_endpoint_id', 31);
            $table->string('conversation_id', 31);
            // `evt_` + a ULID, shared by the deliveries one emission fans out to.
            $table->string('event_id', 31);
            $table->string('event_type', 24);
            // The exact bytes that are signed and sent. Personal data: hidden, and returned by no endpoint.
            $table->text('payload');
            $table->string('status', 16)->default('pending');
            $table->integer('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->smallInteger('last_response_status')->nullable();
            // A short code from a closed set (timeout, connection_failed, destination_not_allowed, destination_unresolved, endpoint_disabled, internal_error, http_<status>); never a body.
            $table->string('last_error', 32)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('webhook_endpoint_id')->references('id')->on('webhook_endpoints')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            // One delivery per endpoint for each transition of a conversation: recording an event again is a no-op.
            $table->unique(['webhook_endpoint_id', 'event_type', 'conversation_id']);
            $table->index(['webhook_endpoint_id', 'created_at']);
        });

        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check CHECK (status IN ('pending', 'succeeded', 'failed'))");
        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_event_type_check CHECK (event_type IN ('conversation.live', 'conversation.ended', 'transcript.ready'))");
        DB::statement('ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_attempts_check CHECK (attempts >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
