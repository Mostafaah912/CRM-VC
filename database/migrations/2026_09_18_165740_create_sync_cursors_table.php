<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `sync_cursors` (035). The cursor only advances when a job completes (CLAUDE.md §5).
 * `entity` is an identifier (PK), not a status, so it has no CHECK; `last_status` reuses the
 * sync_jobs status set since it records the last job's outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_cursors', function (Blueprint $table) {
            $table->string('entity', 20)->primary();
            $table->timestampTz('cursor_value')->nullable();
            $table->timestampTz('last_run_at')->nullable();
            $table->string('last_status', 15)->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE sync_cursors ADD CONSTRAINT sync_cursors_last_status_check CHECK (last_status IN ('running', 'completed', 'failed', 'partial'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_cursors');
    }
};
