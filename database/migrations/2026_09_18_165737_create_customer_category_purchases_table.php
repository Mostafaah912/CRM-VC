<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `customer_category_purchases` (033) — nightly TRUNCATE-and-rebuild aggregate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_category_purchases', function (Blueprint $table) {
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('product_categories')->cascadeOnDelete();
            $table->integer('orders_count');
            $table->integer('items_count');
            $table->bigInteger('revenue');
            $table->timestampTz('last_bought_at')->nullable();

            $table->primary(['customer_id', 'category_id']);
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_category_purchases');
    }
};
