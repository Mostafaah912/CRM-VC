<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P2-09 `woo_webhook_deliveries`: one row per accepted webhook delivery. The UNIQUE woo_delivery_id IS the
 * deduplication, so it holds even when two identical deliveries race. The id is a varchar: real WooCommerce sends a
 * 32-character hash in X-WC-Webhook-Delivery-ID, not an integer. `topic` is the canonical routed topic (CHECK), never
 * the raw header. Rows are not pruned here (a later PruneLogsJob).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woo_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('topic', 30);
            $table->string('woo_delivery_id', 64)->unique();
            $table->timestampTz('received_at')->useCurrent();
        });

        DB::statement("ALTER TABLE woo_webhook_deliveries ADD CONSTRAINT woo_webhook_deliveries_topic_check CHECK (topic IN ('order.created', 'order.updated', 'order.deleted'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('woo_webhook_deliveries');
    }
};
