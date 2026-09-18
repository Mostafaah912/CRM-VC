<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `cohort_snapshots` (031) — TRUNCATE-and-rebuild table. `is_mature` marks periods that
 * have fully elapsed; immature periods are excluded from retention rates (CLAUDE.md §4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cohort_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('cohort_month', 7);
            $table->smallInteger('period_number');
            $table->integer('cohort_size');
            $table->integer('active_customers');
            $table->decimal('retention_rate', 6, 4);
            $table->integer('orders_count');
            $table->bigInteger('revenue');
            $table->bigInteger('cumulative_revenue');
            $table->boolean('is_mature')->default(true);
            $table->timestampTz('computed_at')->useCurrent();

            $table->unique(['cohort_month', 'period_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cohort_snapshots');
    }
};
