<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GATE 1 blocker. A Woo order can have no usable billing phone (real order 15091: a guest, billing.phone empty). PRD §08
 * step 1 says to store it flagged for review; the schema could not (orders.customer_id NOT NULL, no flag), so the sync run
 * stopped on the first one and the order could never be counted. ADDITIVE — nothing is renamed or removed:
 *
 *  - `orders.customer_id` becomes nullable (the FK stays; RESTRICT still protects a customer that has orders);
 *  - `orders.needs_phone_review` boolean NOT NULL DEFAULT false, and a CHECK that an order without a customer is ALWAYS
 *    flagged, so an unattached order can never sit unnoticed outside the review queue;
 *  - `identity_conflicts.customer_id` becomes nullable: a phone-less order has no customer to hang its conflict on (no
 *    placeholder customer is created), so its `no_phone` conflict is recorded against the ORDER;
 *  - a partial UNIQUE index: at most one `no_phone` conflict per Woo order, so a resync or a concurrent worker never doubles it.
 *
 * down() cannot keep rows the old schema cannot hold: it removes the orders without a customer (their items, refunds and
 * status history go by cascade) and the customer-less conflicts. Both are mirror data that Woo, the source of truth, still has.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE orders ALTER COLUMN customer_id DROP NOT NULL');
        DB::statement('ALTER TABLE orders ADD COLUMN needs_phone_review boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_customerless_needs_review_check CHECK (customer_id IS NOT NULL OR needs_phone_review)');

        DB::statement('ALTER TABLE identity_conflicts ALTER COLUMN customer_id DROP NOT NULL');
        DB::statement("CREATE UNIQUE INDEX identity_conflicts_no_phone_order_unique ON identity_conflicts (woo_order_id) WHERE reason = 'no_phone'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS identity_conflicts_no_phone_order_unique');
        DB::statement('DELETE FROM identity_conflicts WHERE customer_id IS NULL');
        DB::statement('ALTER TABLE identity_conflicts ALTER COLUMN customer_id SET NOT NULL');

        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_customerless_needs_review_check');
        DB::statement('DELETE FROM orders WHERE customer_id IS NULL');
        DB::statement('ALTER TABLE orders DROP COLUMN needs_phone_review');
        DB::statement('ALTER TABLE orders ALTER COLUMN customer_id SET NOT NULL');
    }
};
