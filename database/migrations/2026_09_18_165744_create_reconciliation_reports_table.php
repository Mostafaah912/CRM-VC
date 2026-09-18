<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `reconciliation_reports` (038) — GATE 1 evidence. Revenue is bigint Toman; the
 * variance is numeric(7,4) so 1% vs 0.99% is exact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_reports', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->integer('woo_orders');
            $table->integer('crm_orders');
            $table->bigInteger('woo_revenue');
            $table->bigInteger('crm_revenue');
            $table->integer('orders_diff');
            $table->bigInteger('revenue_diff');
            $table->decimal('diff_percent', 7, 4);
            $table->boolean('is_acceptable');
            $table->jsonb('details')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_reports');
    }
};
