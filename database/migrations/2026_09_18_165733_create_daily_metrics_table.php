<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `daily_metrics` (030) — materialized daily rollup, fully rebuildable from orders.
 * No defaults: a rollup row is always written complete by the Sprint 6 builder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_metrics', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->string('jalali_date', 10);
            $table->integer('orders_count');
            $table->bigInteger('revenue');
            $table->bigInteger('refunds');
            $table->bigInteger('net_revenue');
            $table->bigInteger('aov');
            $table->integer('customers_total');
            $table->integer('customers_new');
            $table->integer('customers_repeat');
            $table->bigInteger('revenue_new');
            $table->bigInteger('revenue_repeat');
            $table->timestampTz('computed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_metrics');
    }
};
