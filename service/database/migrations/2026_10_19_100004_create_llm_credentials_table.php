<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained('accounts');
            // A `provider` value of the llm_models catalogue; not a foreign key, since that column is not unique there.
            $table->string('llm_provider', 32);
            $table->text('api_key');
            $table->char('last_four', 4)->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'llm_provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_credentials');
    }
};
