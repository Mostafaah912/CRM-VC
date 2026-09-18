<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CLAUDE.md §2: "Timestamps are timestamptz stored in UTC." Covers the
 * remaining Core-scope tables (PRD §09 migration order 002 users bundle and
 * 011 jobs + failed_jobs) that Laravel's default `timestamp()` left as
 * `timestamp without time zone`. `jobs`/`job_batches` are deliberately left
 * alone — their timestamp-looking columns are raw Unix integers used
 * internally by the queue worker, not Carbon timestamps, so this rule
 * doesn't apply to them. `passkeys` is handled in P0-06 (Authentication),
 * where that table's feature actually lives.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE password_reset_tokens ALTER COLUMN created_at TYPE timestamptz USING created_at AT TIME ZONE 'UTC'");
        DB::statement("ALTER TABLE failed_jobs ALTER COLUMN failed_at TYPE timestamptz USING failed_at AT TIME ZONE 'UTC'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE password_reset_tokens ALTER COLUMN created_at TYPE timestamp(0) without time zone USING created_at AT TIME ZONE 'UTC'");
        DB::statement("ALTER TABLE failed_jobs ALTER COLUMN failed_at TYPE timestamp(0) without time zone USING failed_at AT TIME ZONE 'UTC'");
    }
};
