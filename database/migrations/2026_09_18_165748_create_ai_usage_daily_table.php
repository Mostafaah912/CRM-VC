<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `ai_usage_daily` (041) — spend per day for BudgetGuard (Sprint 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_daily', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->integer('requests')->default(0);
            $table->bigInteger('input_tokens')->default(0);
            $table->bigInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 5)->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_daily');
    }
};
