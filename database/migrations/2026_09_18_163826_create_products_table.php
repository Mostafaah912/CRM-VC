<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `products` — a mirror of Woo products. type/status get CHECK
 * constraints (CLAUDE.md §3) over Woo core's own value sets; the P2-05
 * mapper must map anything else (e.g. a plugin-defined product type)
 * instead of letting the row be rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('woo_product_id')->unique();
            $table->string('name', 250);
            $table->string('slug', 260)->nullable();
            $table->string('type', 20)->default('simple');
            $table->string('status', 20);
            $table->timestampTz('created_at_woo')->nullable();
            $table->timestampTz('synced_at')->useCurrent();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_type_check CHECK (type IN ('simple', 'variable', 'grouped', 'external'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('publish', 'draft', 'pending', 'private'))");
        DB::statement('CREATE INDEX products_name_trgm_index ON products USING gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
