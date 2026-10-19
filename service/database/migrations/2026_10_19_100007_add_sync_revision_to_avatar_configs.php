<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avatar_configs', function (Blueprint $table) {
            // Moves up each time what the provider-side objects are built from changes. A sync run carries
            // the revision it was queued for and may only settle the config while it still holds it.
            $table->unsignedInteger('sync_revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('avatar_configs', function (Blueprint $table) {
            $table->dropColumn('sync_revision');
        });
    }
};
