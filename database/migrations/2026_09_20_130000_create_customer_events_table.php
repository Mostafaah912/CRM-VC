<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P3-04: the customer timeline — one row per thing that happened to a customer, read newest first with a cursor. `payload` is
 * free-form jsonb for the writer's own use; the reader passes only an allowlist of its keys to the browser. `event_type` is a
 * closed set (CHECK, mirrored by CustomerEventType), so a new kind of event is a deliberate migration. `customer_id` is CASCADE:
 * the events go with a customer that is really deleted (soft-deleting keeps them). The index serves the one query the timeline
 * runs: a customer's events ordered by (happened_at DESC, id DESC), which is also the cursor's tie-break.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('event_type', 50);
            $table->jsonb('payload')->nullable();
            $table->timestampTz('happened_at');
            $table->timestampTz('created_at')->default(DB::raw('now()'));
        });

        DB::statement("ALTER TABLE customer_events ADD CONSTRAINT customer_events_event_type_check CHECK (event_type IN ('order_placed', 'order_refunded', 'note_added', 'status_changed'))");
        DB::statement('CREATE INDEX customer_events_customer_happened_at_id_index ON customer_events (customer_id, happened_at DESC, id DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_events');
    }
};
