<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('account_id')->constrained('accounts');
            $table->string('conversation_id', 31);
            // Assigned by the service in arrival order, never taken from the client.
            $table->unsignedInteger('sequence');
            $table->string('source', 8);
            $table->string('type', 12);
            $table->string('role', 8)->nullable();
            $table->text('text')->nullable();
            $table->string('name', 8)->nullable();
            $table->jsonb('data')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->string('idempotency_key', 128);
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->unique(['conversation_id', 'idempotency_key']);
            $table->unique(['conversation_id', 'sequence']);
        });

        DB::statement("ALTER TABLE conversation_events ADD CONSTRAINT conversation_events_source_check CHECK (source IN ('client', 'provider'))");
        DB::statement("ALTER TABLE conversation_events ADD CONSTRAINT conversation_events_type_check CHECK (type IN ('utterance', 'lifecycle'))");
        DB::statement("ALTER TABLE conversation_events ADD CONSTRAINT conversation_events_role_check CHECK (role IS NULL OR role IN ('user', 'avatar'))");
        DB::statement("ALTER TABLE conversation_events ADD CONSTRAINT conversation_events_name_check CHECK (name IS NULL OR name IN ('live', 'error'))");
        DB::statement("ALTER TABLE conversation_events ADD CONSTRAINT conversation_events_data_check CHECK (data IS NULL OR jsonb_typeof(data) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_events');
    }
};
