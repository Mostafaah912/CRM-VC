<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bugfix (Sprint 6, docs/architecture/sprint-6.md "تشخیص"): PRD §09's `products` table has no `sku`/
 * `price` column — deliberately, per P2-05 (docs/architecture/sprint-2.md line 77), since PRD never
 * defines a "default variation" for a simple WooCommerce product. That gap makes every simple-product
 * order line permanently unresolvable (P2-06's documented "known limitation"; ARCHITECTURE.md Open
 * Items), confirmed on dev at 0% resolution.
 *
 * Explicit project-owner decision (not PRD, not invented here): add these two nullable columns so a
 * simple product can resolve directly by its own SKU, instead of needing a synthetic default-variation
 * row. `ProductDto`/`ProductMapper` (P2-05) already read `sku`/`price` from Woo's payload — they were
 * captured and then silently dropped before `products` had anywhere to put them. This migration
 * deviates from PRD §09's literal schema; the deviation is recorded in ARCHITECTURE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sku', 80)->nullable()->after('slug');
            $table->bigInteger('price')->nullable()->after('sku');
        });

        // Same partial-unique pattern as product_variations.sku: one product may own a SKU, many may have none.
        DB::statement('CREATE UNIQUE INDEX products_sku_unique ON products (sku) WHERE sku IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_sku_unique');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sku', 'price']);
        });
    }
};
