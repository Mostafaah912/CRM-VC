<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P0-06 (Authentication): passkeys is an auth-feature table, so its
 * `timestamp without time zone` columns (left as a known gap in P0-05,
 * see ARCHITECTURE.md) are converted here, per CLAUDE.md §2.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['created_at', 'updated_at', 'last_used_at'] as $column) {
            DB::statement("ALTER TABLE passkeys ALTER COLUMN {$column} TYPE timestamptz USING {$column} AT TIME ZONE 'UTC'");
        }
    }

    public function down(): void
    {
        foreach (['created_at', 'updated_at', 'last_used_at'] as $column) {
            DB::statement("ALTER TABLE passkeys ALTER COLUMN {$column} TYPE timestamp(0) without time zone USING {$column} AT TIME ZONE 'UTC'");
        }
    }
};
