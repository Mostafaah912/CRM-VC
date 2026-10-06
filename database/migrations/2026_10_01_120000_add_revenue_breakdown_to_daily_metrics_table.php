<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6-14 phase 3: PRD §09's `daily_metrics` (030) literal column list has no revenue breakdown — every
 * revenue-showing page only had `revenue`/`net_revenue`, never "goods vs. shipping". This is a deliberate,
 * documented deviation from the PRD schema (ARCHITECTURE.md, "P6-16"), not an oversight: `product_revenue`
 * = Σ(subtotal - discount_total), `shipping_revenue` = Σ(shipping_total), both over the same "realized,
 * not fully refunded" order set `DailyMetricsService::rebuild()` already uses for `revenue`/`net_revenue`.
 * `daily_metrics` already has rows (it is rebuilt routinely, never empty on a real environment), so a bare
 * NOT NULL ADD COLUMN would fail on Postgres — a transient `default(0)` lets the ALTER succeed; the full
 * history rebuild this same task runs immediately after overwrites every row with the real computed value,
 * same as every other column here (which carries no DEFAULT because the builder always writes it complete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_metrics', function (Blueprint $table) {
            $table->bigInteger('product_revenue')->default(0)->after('net_revenue');
            $table->bigInteger('shipping_revenue')->default(0)->after('product_revenue');
        });

        // Dropped immediately, not kept: every other column here has no stored default either (the
        // builder always writes a row complete) — the transient default above exists only so this
        // ALTER succeeds against daily_metrics' existing rows, backfilled by the rebuild that follows.
        // Raw DDL, not Schema::change() (which needs doctrine/dbal, not installed here) — a migration
        // file is DDL, not the app/ query-building code CLAUDE.md §3's raw-SQL ban targets.
        DB::statement('ALTER TABLE daily_metrics ALTER COLUMN product_revenue DROP DEFAULT');
        DB::statement('ALTER TABLE daily_metrics ALTER COLUMN shipping_revenue DROP DEFAULT');
    }

    public function down(): void
    {
        Schema::table('daily_metrics', function (Blueprint $table) {
            $table->dropColumn(['product_revenue', 'shipping_revenue']);
        });
    }
};
