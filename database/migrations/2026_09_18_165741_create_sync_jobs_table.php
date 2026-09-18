<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `sync_jobs` (036). cursor_from/cursor_to hold the frozen window (cursor_to = now() at
 * job start). The three counters default to 0 so a job can be inserted as `running` before any
 * page is processed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 20);
            $table->string('mode', 12);
            $table->string('status', 15)->default('running');
            $table->timestampTz('cursor_from')->nullable();
            $table->timestampTz('cursor_to')->nullable();
            $table->integer('pages_processed')->default(0);
            $table->integer('records_processed')->default(0);
            $table->integer('records_failed')->default(0);
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();
            $table->text('error')->nullable();
        });

        DB::statement("ALTER TABLE sync_jobs ADD CONSTRAINT sync_jobs_mode_check CHECK (mode IN ('full', 'incremental', 'webhook'))");
        DB::statement("ALTER TABLE sync_jobs ADD CONSTRAINT sync_jobs_status_check CHECK (status IN ('running', 'completed', 'failed', 'partial'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_jobs');
    }
};
