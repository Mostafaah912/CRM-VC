<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P3-05: deleting a note writes a `note_deleted` timeline event, so the closed set of customer_events.event_type (P3-04's
 * migration is published and untouched) gains one value. Mirrors CustomerEventType. down() removes the rows of the new type first,
 * because the old constraint cannot hold them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE customer_events DROP CONSTRAINT customer_events_event_type_check');
        DB::statement("ALTER TABLE customer_events ADD CONSTRAINT customer_events_event_type_check CHECK (event_type IN ('order_placed', 'order_refunded', 'note_added', 'note_deleted', 'status_changed'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM customer_events WHERE event_type = 'note_deleted'");
        DB::statement('ALTER TABLE customer_events DROP CONSTRAINT customer_events_event_type_check');
        DB::statement("ALTER TABLE customer_events ADD CONSTRAINT customer_events_event_type_check CHECK (event_type IN ('order_placed', 'order_refunded', 'note_added', 'status_changed'))");
    }
};
