<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `occurred_at` is when a line was spoken, and a transcript is ordered by it. A browser writes milliseconds
     * and a provider microseconds; a column that keeps whole seconds would tie the lines of one second and
     * leave their order to the order they arrived in. Widened here rather than in the table's own migration,
     * so a database that already ran it migrates forward.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE conversation_events ALTER COLUMN occurred_at TYPE timestamp(6) without time zone');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE conversation_events ALTER COLUMN occurred_at TYPE timestamp(0) without time zone');
    }
};
