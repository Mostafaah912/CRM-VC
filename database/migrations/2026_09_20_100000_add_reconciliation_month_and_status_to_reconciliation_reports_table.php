<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P2-11: the PRD §09 `reconciliation_reports` table (P1-04, GATE 1 evidence) gets what a per-month, re-runnable report
 * needs — ADDITIVELY; every PRD column keeps its name and type:
 *
 *  - `jalali_month` varchar(7) UNIQUE: one report per Jalali month, replaced when the month is reconciled again;
 *  - `status` green | red | failed (CHECK): "failed" is a month that could not be read at all;
 *  - `error_message`, `reconciled_at`, `updated_at`.
 *
 * The seven measurement columns become nullable so a failed month can be stored — and a CHECK makes that the ONLY case
 * where they may be empty: a green or red report always carries every measurement, and `is_acceptable` always equals
 * "status is green", so a gap can never look like a result. The table was never written before P2-11, so the new
 * NOT NULL columns need no backfill (a non-empty table would make this migration fail loudly).
 */
return new class extends Migration
{
    private const MEASUREMENTS = ['woo_orders', 'crm_orders', 'woo_revenue', 'crm_revenue', 'orders_diff', 'revenue_diff', 'diff_percent'];

    public function up(): void
    {
        Schema::table('reconciliation_reports', function (Blueprint $table) {
            $table->string('jalali_month', 7)->unique();
            $table->string('status', 10);
            $table->text('error_message')->nullable();
            $table->timestampTz('reconciled_at')->nullable();
            $table->timestampTz('updated_at')->useCurrent();
        });

        foreach (self::MEASUREMENTS as $column) {
            DB::statement("ALTER TABLE reconciliation_reports ALTER COLUMN {$column} DROP NOT NULL");
        }

        $measured = implode(' AND ', array_map(fn (string $column): string => "{$column} IS NOT NULL", self::MEASUREMENTS));

        DB::statement("ALTER TABLE reconciliation_reports ADD CONSTRAINT reconciliation_reports_status_check CHECK (status IN ('green', 'red', 'failed'))");
        DB::statement("ALTER TABLE reconciliation_reports ADD CONSTRAINT reconciliation_reports_measured_check CHECK (status = 'failed' OR ({$measured}))");
        DB::statement("ALTER TABLE reconciliation_reports ADD CONSTRAINT reconciliation_reports_acceptable_check CHECK (is_acceptable = (status = 'green'))");
    }

    /** Failed months hold no measurements, which the PRD's NOT NULL columns cannot store: they are removed. */
    public function down(): void
    {
        DB::statement("DELETE FROM reconciliation_reports WHERE status = 'failed'");

        DB::statement('ALTER TABLE reconciliation_reports DROP CONSTRAINT reconciliation_reports_acceptable_check');
        DB::statement('ALTER TABLE reconciliation_reports DROP CONSTRAINT reconciliation_reports_measured_check');
        DB::statement('ALTER TABLE reconciliation_reports DROP CONSTRAINT reconciliation_reports_status_check');

        Schema::table('reconciliation_reports', function (Blueprint $table) {
            $table->dropColumn(['jalali_month', 'status', 'error_message', 'reconciled_at', 'updated_at']);
        });

        foreach (self::MEASUREMENTS as $column) {
            DB::statement("ALTER TABLE reconciliation_reports ALTER COLUMN {$column} SET NOT NULL");
        }
    }
};
