<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `order_items`. product_id/variation_id are NULLable: an
 * unresolvable product never rejects an order — the item keeps sku and
 * name_snapshot (CLAUDE.md §5). If a catalog row is later deleted the link
 * is nulled and the item (revenue) survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->bigInteger('woo_item_id')->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('variation_id')->nullable()->constrained('product_variations')->nullOnDelete();
            $table->string('sku', 80)->nullable();
            $table->string('name_snapshot', 250);
            $table->integer('qty')->default(1);
            $table->bigInteger('unit_price')->default(0);
            $table->bigInteger('line_subtotal')->default(0);
            $table->bigInteger('line_total')->default(0);
            $table->integer('refunded_qty')->default(0);
            $table->bigInteger('refunded_amount')->default(0);
            $table->timestampsTz();

            $table->unique(['order_id', 'woo_item_id']);
            $table->index('product_id');
            $table->index('variation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
