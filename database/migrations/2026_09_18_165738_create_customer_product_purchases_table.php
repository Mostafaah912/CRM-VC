<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `customer_product_purchases` (034) — nightly TRUNCATE-and-rebuild aggregate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_product_purchases', function (Blueprint $table) {
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('orders_count');
            $table->integer('items_count');
            $table->bigInteger('revenue');
            $table->timestampTz('last_bought_at')->nullable();

            $table->primary(['customer_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_product_purchases');
    }
};
