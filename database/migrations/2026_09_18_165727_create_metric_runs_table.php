<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `metric_runs` (026). One row per Metrics Engine run; `thresholds` snapshots the
 * store's real churn percentiles so every run is reproducible (CLAUDE.md §4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_runs', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 10);
            $table->string('definition_version', 10)->default('v1');
            $table->string('status', 15)->default('running');
            $table->integer('customers_processed')->default(0);
            $table->jsonb('thresholds')->nullable();
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();
            $table->text('error')->nullable();
        });

        DB::statement("ALTER TABLE metric_runs ADD CONSTRAINT metric_runs_mode_check CHECK (mode IN ('full', 'dirty'))");
        DB::statement("ALTER TABLE metric_runs ADD CONSTRAINT metric_runs_status_check CHECK (status IN ('running', 'completed', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_runs');
    }
};
