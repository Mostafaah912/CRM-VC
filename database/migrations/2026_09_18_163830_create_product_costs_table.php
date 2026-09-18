<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `product_costs` — created empty in Phase 1 (decision D11: a global
 * margin rate is used instead). Manual data that Woo cannot restore, so the
 * variation FK is RESTRICT: a variation with costs cannot be silently deleted.
 * Only the `manual` source exists in the PRD; a new source needs a new migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variation_id')->constrained('product_variations')->restrictOnDelete();
            $table->bigInteger('unit_cost');
            $table->date('effective_from');
            $table->string('source', 20)->default('manual');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['variation_id', 'effective_from']);
        });

        DB::statement("ALTER TABLE product_costs ADD CONSTRAINT product_costs_source_check CHECK (source IN ('manual'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('product_costs');
    }
};
