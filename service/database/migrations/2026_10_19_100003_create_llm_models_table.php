<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The platform catalogue: owned by the platform, shared by every account, so no account_id.
        Schema::create('llm_models', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('provider', 32)->index();
            $table->string('model', 128);
            $table->string('display_name', 128);
            $table->string('base_url', 512);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_models');
    }
};
