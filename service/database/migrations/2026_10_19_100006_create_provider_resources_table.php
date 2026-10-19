<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The ledger of objects this service created inside a provider, per account and per provider key.
        Schema::create('provider_resources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained('accounts');
            $table->string('provider', 32);
            // First 16 hex characters of the sha256 of the provider key used, so the ledger stays right when an account changes key.
            $table->char('key_fingerprint', 16);
            $table->string('kind', 16);
            $table->string('natural_key', 255);
            $table->string('external_id', 255);
            $table->timestamps();

            $table->unique(['account_id', 'provider', 'key_fingerprint', 'kind', 'natural_key']);
        });

        DB::statement("ALTER TABLE provider_resources ADD CONSTRAINT provider_resources_kind_check CHECK (kind IN ('llm_secret', 'vendor_secret', 'voice'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_resources');
    }
};
