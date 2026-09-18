<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `product_affinities` (032). entity ids are polymorphic per `level`
 * (variation/product/category/basket), so they are plain bigints, not FKs. Pairs with
 * lift <= 1 or too few co-customers are never stored — that rule lives in the Sprint 6 query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_affinities', function (Blueprint $table) {
            $table->id();
            $table->string('level', 10);
            $table->bigInteger('entity_a_id');
            $table->bigInteger('entity_b_id');
            $table->integer('co_customers');
            $table->integer('a_customers');
            $table->integer('b_customers');
            $table->decimal('support', 9, 6);
            $table->decimal('confidence', 9, 6);
            $table->decimal('lift', 9, 4);
            $table->timestampTz('computed_at')->useCurrent();

            $table->unique(['level', 'entity_a_id', 'entity_b_id']);
        });

        DB::statement("ALTER TABLE product_affinities ADD CONSTRAINT product_affinities_level_check CHECK (level IN ('variation', 'product', 'category', 'basket'))");
        DB::statement('ALTER TABLE product_affinities ADD CONSTRAINT product_affinities_distinct_entities_check CHECK (entity_a_id <> entity_b_id)');
        DB::statement('CREATE INDEX product_affinities_level_entity_a_lift_index ON product_affinities (level, entity_a_id, lift DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_affinities');
    }
};
