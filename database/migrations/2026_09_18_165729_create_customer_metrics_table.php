<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `customer_metrics` (027). A DERIVED table: every value is recomputed from orders and
 * SET, never incremented (CLAUDE.md §1/§3), so rows cascade with their customer and can be
 * rebuilt from scratch. money/CLV are bigint Toman. `rfm_segment` gets a CHECK over the eight
 * segments listed in PRD §12; the calculation itself is Sprint 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_metrics', function (Blueprint $table) {
            $table->foreignId('customer_id')->primary()->constrained('customers')->cascadeOnDelete();
            $table->timestampTz('first_order_at')->nullable();
            $table->timestampTz('last_order_at')->nullable();
            $table->integer('total_orders')->default(0);
            $table->bigInteger('total_revenue')->default(0);
            $table->bigInteger('total_refunded')->default(0);
            $table->bigInteger('aov')->default(0);
            $table->integer('recency_days')->nullable();
            $table->integer('frequency')->default(0);
            $table->bigInteger('monetary')->default(0);
            $table->smallInteger('r_score')->nullable();
            $table->smallInteger('f_score')->nullable();
            $table->smallInteger('m_score')->nullable();
            $table->string('rfm_score', 3)->nullable();
            $table->string('rfm_segment', 30)->nullable();
            $table->decimal('avg_days_between', 8, 2)->nullable();
            $table->decimal('median_days_between', 8, 2)->nullable();
            $table->decimal('purchase_cycle_days', 8, 2)->nullable();
            $table->timestampTz('expected_next_order_at')->nullable();
            $table->bigInteger('clv_historical')->default(0);
            $table->bigInteger('clv_estimated')->nullable();
            $table->string('clv_confidence', 8)->nullable();
            $table->decimal('churn_risk_score', 5, 2)->nullable();
            $table->string('churn_risk_level', 10)->nullable();
            $table->text('churn_reason')->nullable();
            $table->integer('distinct_categories')->default(0);
            $table->string('cohort_month', 7)->nullable();
            $table->foreignId('metric_run_id')->nullable()->constrained('metric_runs')->nullOnDelete();
            $table->timestampTz('computed_at')->useCurrent();

            $table->index('recency_days');
            $table->index('rfm_segment');
            $table->index('churn_risk_level');
            $table->index('cohort_month');
            $table->index('expected_next_order_at');
        });

        DB::statement('ALTER TABLE customer_metrics ADD CONSTRAINT customer_metrics_r_score_check CHECK (r_score BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE customer_metrics ADD CONSTRAINT customer_metrics_f_score_check CHECK (f_score BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE customer_metrics ADD CONSTRAINT customer_metrics_m_score_check CHECK (m_score BETWEEN 1 AND 5)');
        DB::statement("ALTER TABLE customer_metrics ADD CONSTRAINT customer_metrics_clv_confidence_check CHECK (clv_confidence IN ('low', 'medium', 'high'))");
        DB::statement("ALTER TABLE customer_metrics ADD CONSTRAINT customer_metrics_churn_risk_level_check CHECK (churn_risk_level IN ('low', 'medium', 'high', 'lost'))");
        DB::statement("ALTER TABLE customer_metrics ADD CONSTRAINT customer_metrics_rfm_segment_check CHECK (rfm_segment IN ('champion', 'loyal', 'promising', 'new_customer', 'at_risk', 'cant_lose', 'hibernating', 'lost'))");
        DB::statement('CREATE INDEX customer_metrics_monetary_desc_index ON customer_metrics (monetary DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_metrics');
    }
};
