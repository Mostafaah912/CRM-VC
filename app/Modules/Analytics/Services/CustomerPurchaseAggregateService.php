<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Support\CustomerPurchaseAggregateSummary;
use Illuminate\Support\Facades\DB;

/**
 * PRD §16's nightly aggregate tables — one INSERT...SELECT per table, never a PHP loop over
 * customers/orders/order_items (CLAUDE.md §3). Reads `orders`/`order_items`/`product_category_product`
 * with the query builder / raw SQL only. Analytics is a documented, blanket Rule 7 exception
 * (ARCHITECTURE.md §"Confirmed decisions": "app/ broadly bans raw SQL except the Metrics/Analytics
 * modules"), unlike Catalog's single named-file exception — this class needed no arch-test change.
 *
 * Both tables are rebuilt from scratch every run (TRUNCATE, exactly as PRD §16's own SQL and the two
 * tables' migration docblocks already call them: "nightly TRUNCATE-and-rebuild aggregate") rather than
 * diffed incrementally — CLAUDE.md §1 requires every derived table to be fully rebuildable from source
 * and to store nothing that doesn't exist elsewhere. No controller or service reads either table yet
 * (checked before writing this), so TRUNCATE's brief ACCESS EXCLUSIVE lock has no live reader to block;
 * DELETE+INSERT was considered and rejected because it would reverse an already-documented decision
 * (the migration docblocks) for a concurrency problem that does not exist yet. If a later Sprint 6 task
 * (dashboard/affinity pages) starts reading these tables live during the nightly chain, that tradeoff
 * should be revisited — flagged in docs/architecture/sprint-6.md, not solved here.
 *
 * The TRUNCATE and the INSERT that follows it run inside one transaction (Postgres TRUNCATE is
 * transactional) so a failure mid-rebuild rolls back to the previous night's data instead of leaving
 * the table empty.
 *
 * Deliberately different from Metrics' `BaseAggregateService` (PRD §11's base aggregates): that query
 * excludes `is_fully_refunded` orders entirely. PRD §16's SQL for these two tables has no such filter —
 * a partially (or fully) refunded order's surviving items still count here, since `line_total -
 * refunded_amount` already nets the refunded portion out of `revenue`. Implemented exactly as PRD §16
 * specifies; the difference from Base Aggregates is intentional, not reconciled.
 */
final class CustomerPurchaseAggregateService
{
    public function rebuild(): CustomerPurchaseAggregateSummary
    {
        $start = microtime(true);

        [$productRows, $categoryRows] = DB::transaction(fn (): array => [
            $this->rebuildProductPurchases(),
            $this->rebuildCategoryPurchases(),
        ]);

        return new CustomerPurchaseAggregateSummary(
            productRows: $productRows,
            categoryRows: $categoryRows,
            elapsedMs: (int) round((microtime(true) - $start) * 1000),
        );
    }

    private function rebuildProductPurchases(): int
    {
        DB::table('customer_product_purchases')->truncate();

        return DB::affectingStatement(<<<'SQL'
            INSERT INTO customer_product_purchases (
                customer_id, product_id, orders_count, items_count, revenue, last_bought_at
            )
            SELECT
                o.customer_id,
                oi.product_id,
                COUNT(DISTINCT o.id)::integer,
                SUM(oi.qty)::integer,
                SUM(oi.line_total - oi.refunded_amount)::bigint,
                MAX(o.ordered_at)
            FROM orders o
            JOIN order_items oi ON oi.order_id = o.id
            WHERE o.is_realized = true
              AND o.deleted_at IS NULL
              AND oi.product_id IS NOT NULL
            GROUP BY o.customer_id, oi.product_id
            SQL);
    }

    private function rebuildCategoryPurchases(): int
    {
        DB::table('customer_category_purchases')->truncate();

        return DB::affectingStatement(<<<'SQL'
            INSERT INTO customer_category_purchases (
                customer_id, category_id, orders_count, items_count, revenue, last_bought_at
            )
            SELECT
                o.customer_id,
                pcp.category_id,
                COUNT(DISTINCT o.id)::integer,
                SUM(oi.qty)::integer,
                SUM(oi.line_total - oi.refunded_amount)::bigint,
                MAX(o.ordered_at)
            FROM orders o
            JOIN order_items oi ON oi.order_id = o.id
            JOIN product_category_product pcp ON pcp.product_id = oi.product_id
            WHERE o.is_realized = true
              AND o.deleted_at IS NULL
              AND oi.product_id IS NOT NULL
            GROUP BY o.customer_id, pcp.category_id
            SQL);
    }
}
