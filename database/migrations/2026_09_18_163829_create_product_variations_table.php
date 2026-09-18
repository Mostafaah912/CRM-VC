<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `product_variations`. price is integer Toman (bigint, CLAUDE.md §2).
 * SKU is unique only when present — order-line resolution (PRD §10) can fall
 * back to variation_id when a SKU is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->bigInteger('woo_variation_id')->nullable()->unique();
            $table->string('sku', 80)->nullable();
            $table->jsonb('attributes')->default(DB::raw("'{}'::jsonb"));
            $table->bigInteger('price')->nullable();
            $table->string('status', 20);
            $table->timestampTz('synced_at')->useCurrent();
            $table->timestampsTz();

            $table->index('product_id');
        });

        DB::statement("ALTER TABLE product_variations ADD CONSTRAINT product_variations_status_check CHECK (status IN ('publish', 'draft', 'pending', 'private'))");
        DB::statement('CREATE UNIQUE INDEX product_variations_sku_unique ON product_variations (sku) WHERE sku IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variations');
    }
};
