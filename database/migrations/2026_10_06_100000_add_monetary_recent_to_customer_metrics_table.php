<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6-20, product-owner decision (ARCHITECTURE.md): RFM's "M" can now be scored from only the last
 * `metrics.monetary.window_days` days of realized orders instead of lifetime `monetary` — a deliberate
 * deviation from PRD §12's lifetime-NTILE formula, to stop 3 years of Toman inflation making an old
 * order compare at face value against a recent one. `monetary` itself is untouched (still the full
 * lifetime sum, still what `lifetime` mode scores from); this is a new, separate, nullable column —
 * NULL means "no realized purchase in the window" (a real signal BaseAggregateService/RfmCalculator
 * read directly), not "zero", so no DEFAULT is needed even though the table already has rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_metrics', function (Blueprint $table) {
            $table->bigInteger('monetary_recent')->nullable()->after('monetary');
        });
    }

    public function down(): void
    {
        Schema::table('customer_metrics', function (Blueprint $table) {
            $table->dropColumn('monetary_recent');
        });
    }
};
