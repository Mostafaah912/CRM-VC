<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `orders` — a mirror of Woo orders. All money is bigint Toman.
 *
 * `status` is the raw Woo slug with no CHECK and no app-side enum: order
 * statuses are store-defined (a custom status must never reject an order,
 * CLAUDE.md §5) and CLAUDE.md §3 forbids hardcoding status strings — which
 * of them are "realized" comes from config('woo.realized_statuses').
 *
 * `net_revenue` is a STORED generated column added with DB::statement()
 * (PRD migration rule 3) so it can never drift from total - refunded_total.
 * customer_id is RESTRICT: a customer with orders is never hard deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('woo_order_id')->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('number', 40)->nullable();
            $table->string('status', 30);
            $table->boolean('is_realized')->default(false);
            $table->bigInteger('total')->default(0);
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('discount_total')->default(0);
            $table->bigInteger('shipping_total')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('refunded_total')->default(0);
            $table->boolean('is_fully_refunded')->default(false);
            $table->jsonb('coupon_codes')->default(DB::raw("'[]'::jsonb"));
            $table->string('payment_method', 60)->nullable();
            $table->timestampTz('ordered_at');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('woo_modified_at')->nullable();
            $table->timestampTz('synced_at')->useCurrent();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE orders ADD COLUMN net_revenue bigint GENERATED ALWAYS AS (total - refunded_total) STORED');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amounts_check CHECK (total >= 0 AND refunded_total >= 0)');
        DB::statement('CREATE INDEX orders_customer_ordered_at_index ON orders (customer_id, ordered_at DESC)');
        DB::statement('CREATE INDEX orders_realized_ordered_at_index ON orders (is_realized, ordered_at) WHERE is_realized AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
