<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `order_status_history`. from/to statuses are raw Woo slugs (see
 * `orders`). `source` only knows `sync` — the sole value in the PRD; a
 * webhook/manual source needs its own migration when that feature exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->timestampTz('changed_at');
            $table->string('source', 20)->default('sync');

            $table->index(['order_id', 'changed_at']);
        });

        DB::statement("ALTER TABLE order_status_history ADD CONSTRAINT order_status_history_source_check CHECK (source IN ('sync'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};
