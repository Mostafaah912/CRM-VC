<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `refunds`. woo_refund_id UNIQUE makes refund sync idempotent.
 * orders.refunded_total is always RECOMPUTED as SUM(refunds.amount), never
 * incremented (CLAUDE.md §3) — that recompute belongs to the sync sprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->bigInteger('woo_refund_id')->unique();
            $table->bigInteger('amount');
            $table->boolean('is_full')->default(false);
            $table->text('reason')->nullable();
            $table->timestampTz('refunded_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
