<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained('accounts');
            // Validated against the configured credential providers at the HTTP boundary, so no provider name lands here.
            $table->string('provider', 32);
            // Ciphertext of the Eloquent `encrypted` cast, hence text.
            $table->text('api_key');
            // Null when the key is too short to reveal any of it.
            $table->char('last_four', 4)->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_credentials');
    }
};
