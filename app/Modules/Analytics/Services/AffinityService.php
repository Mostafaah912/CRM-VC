<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Enums\AffinityLevel;
use App\Modules\Analytics\Support\AffinitySummary;
use Illuminate\Support\Facades\DB;

/**
 * PRD §16's `product_affinities` (P6-05) — "one pattern for all levels": for every unordered pair of
 * entities co-bought by the same customer (or, for 'basket', appearing in the same order — PRD's C1
 * conflict table, C5: "customer level is primary; basket level is just one more `level` value in the
 * same table", never a separate mechanism), compute
 *
 *   support    = co_customers / total_customers
 *   confidence = co_customers / a_customers            (confidence(A -> B))
 *   lift       = confidence / (b_customers / total_customers)
 *
 * and store the pair (entity_a_id < entity_b_id, so each unordered pair is written once, never both
 * directions) only when co_customers is at least the level's minimum AND lift > 1.0 — a lift of exactly
 * 1.0 means B is bought independently of A (no real affinity) and is deliberately excluded, not rounded
 * in. `product`/`category` read the already-built P6-01 aggregate tables (one row per real customer
 * purchase, already filtered to realized, non-deleted, resolved order_items); `variation`/`basket` have
 * no such aggregate table, so they read `order_items`/`orders` directly with the same filters. For
 * `basket`, the `co_customers`/`a_customers`/`b_customers` columns hold basket (order) counts, not
 * customer counts — the schema reuses the same column names across every `level` (PRD's own C5
 * resolution), not a mismatch.
 *
 * The whole table is truncated and rebuilt every run (fully derivable from source, CLAUDE.md §1), inside
 * one transaction so a mid-rebuild failure rolls back to the previous run's data instead of leaving the
 * table half-written.
 */
final class AffinityService
{
    public function rebuild(): AffinitySummary
    {
        $start = microtime(true);

        [$category, $product, $variation, $basket] = DB::transaction(function (): array {
            DB::table('product_affinities')->truncate();

            return [
                $this->rebuildCategoryLevel(),
                $this->rebuildProductLevel(),
                $this->rebuildVariationLevel(),
                $this->rebuildBasketLevel(),
            ];
        });

        return new AffinitySummary(
            categoryRows: $category,
            productRows: $product,
            variationRows: $variation,
            basketRows: $basket,
            elapsedMs: (int) round((microtime(true) - $start) * 1000),
        );
    }

    /**
     * Read-only: the strongest `$limit` pairs for one level, by lift descending (P6-06's "Top Affinity"
     * dashboard widget, PRD §07's `AffinityService::top()`). Plain read of the already-rebuilt table —
     * no computation happens here.
     *
     * @return list<array{entity_a_id: int, entity_b_id: int, co_customers: int, support: float, confidence: float, lift: float, level: string}>
     */
    public function top(AffinityLevel $level = AffinityLevel::Product, int $limit = 10): array
    {
        $rows = DB::table('product_affinities')
            ->where('level', $level->value)
            ->orderByDesc('lift')
            ->limit($limit)
            ->get(['entity_a_id', 'entity_b_id', 'co_customers', 'support', 'confidence', 'lift'])
            ->map(fn (object $row): array => [
                'entity_a_id' => (int) $row->entity_a_id,
                'entity_b_id' => (int) $row->entity_b_id,
                'co_customers' => (int) $row->co_customers,
                'support' => (float) $row->support,
                'confidence' => (float) $row->confidence,
                'lift' => (float) $row->lift,
                'level' => $level->value,
            ])
            ->all();

        return array_values($rows);
    }

    /** PRD §16: category level, source customer_category_purchases, min co-purchase 20. */
    private function rebuildCategoryLevel(): int
    {
        return DB::affectingStatement(<<<'SQL'
            WITH pairs AS (
                SELECT customer_id, category_id FROM customer_category_purchases
            ),
            totals AS (
                SELECT category_id, COUNT(DISTINCT customer_id) AS customers FROM pairs GROUP BY category_id
            ),
            population AS (
                SELECT COUNT(DISTINCT customer_id) AS total FROM pairs
            ),
            co AS (
                SELECT p1.category_id AS a_id, p2.category_id AS b_id, COUNT(DISTINCT p1.customer_id) AS co_customers
                FROM pairs p1
                JOIN pairs p2 ON p2.customer_id = p1.customer_id AND p2.category_id > p1.category_id
                GROUP BY p1.category_id, p2.category_id
                HAVING COUNT(DISTINCT p1.customer_id) >= 20
            )
            INSERT INTO product_affinities (level, entity_a_id, entity_b_id, co_customers, a_customers, b_customers, support, confidence, lift, computed_at)
            SELECT
                'category', co.a_id, co.b_id, co.co_customers, ta.customers, tb.customers,
                co.co_customers::numeric / population.total,
                co.co_customers::numeric / ta.customers,
                (co.co_customers::numeric / ta.customers) / (tb.customers::numeric / population.total),
                NOW()
            FROM co
            JOIN totals ta ON ta.category_id = co.a_id
            JOIN totals tb ON tb.category_id = co.b_id
            CROSS JOIN population
            WHERE (co.co_customers::numeric / ta.customers) / (tb.customers::numeric / population.total) > 1.0
            SQL);
    }

    /** PRD §16: product level, source customer_product_purchases, min co-purchase 10. */
    private function rebuildProductLevel(): int
    {
        return DB::affectingStatement(<<<'SQL'
            WITH pairs AS (
                SELECT customer_id, product_id FROM customer_product_purchases
            ),
            totals AS (
                SELECT product_id, COUNT(DISTINCT customer_id) AS customers FROM pairs GROUP BY product_id
            ),
            population AS (
                SELECT COUNT(DISTINCT customer_id) AS total FROM pairs
            ),
            co AS (
                SELECT p1.product_id AS a_id, p2.product_id AS b_id, COUNT(DISTINCT p1.customer_id) AS co_customers
                FROM pairs p1
                JOIN pairs p2 ON p2.customer_id = p1.customer_id AND p2.product_id > p1.product_id
                GROUP BY p1.product_id, p2.product_id
                HAVING COUNT(DISTINCT p1.customer_id) >= 10
            )
            INSERT INTO product_affinities (level, entity_a_id, entity_b_id, co_customers, a_customers, b_customers, support, confidence, lift, computed_at)
            SELECT
                'product', co.a_id, co.b_id, co.co_customers, ta.customers, tb.customers,
                co.co_customers::numeric / population.total,
                co.co_customers::numeric / ta.customers,
                (co.co_customers::numeric / ta.customers) / (tb.customers::numeric / population.total),
                NOW()
            FROM co
            JOIN totals ta ON ta.product_id = co.a_id
            JOIN totals tb ON tb.product_id = co.b_id
            CROSS JOIN population
            WHERE (co.co_customers::numeric / ta.customers) / (tb.customers::numeric / population.total) > 1.0
            SQL);
    }

    /** PRD §16: variation level, source order_items directly (no aggregate table exists), min co-purchase 5. */
    private function rebuildVariationLevel(): int
    {
        return DB::affectingStatement(<<<'SQL'
            WITH pairs AS (
                SELECT DISTINCT o.customer_id, oi.variation_id
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                WHERE o.is_realized = true AND o.deleted_at IS NULL AND o.customer_id IS NOT NULL AND oi.variation_id IS NOT NULL
            ),
            totals AS (
                SELECT variation_id, COUNT(DISTINCT customer_id) AS customers FROM pairs GROUP BY variation_id
            ),
            population AS (
                SELECT COUNT(DISTINCT customer_id) AS total FROM pairs
            ),
            co AS (
                SELECT p1.variation_id AS a_id, p2.variation_id AS b_id, COUNT(DISTINCT p1.customer_id) AS co_customers
                FROM pairs p1
                JOIN pairs p2 ON p2.customer_id = p1.customer_id AND p2.variation_id > p1.variation_id
                GROUP BY p1.variation_id, p2.variation_id
                HAVING COUNT(DISTINCT p1.customer_id) >= 5
            )
            INSERT INTO product_affinities (level, entity_a_id, entity_b_id, co_customers, a_customers, b_customers, support, confidence, lift, computed_at)
            SELECT
                'variation', co.a_id, co.b_id, co.co_customers, ta.customers, tb.customers,
                co.co_customers::numeric / population.total,
                co.co_customers::numeric / ta.customers,
                (co.co_customers::numeric / ta.customers) / (tb.customers::numeric / population.total),
                NOW()
            FROM co
            JOIN totals ta ON ta.variation_id = co.a_id
            JOIN totals tb ON tb.variation_id = co.b_id
            CROSS JOIN population
            WHERE (co.co_customers::numeric / ta.customers) / (tb.customers::numeric / population.total) > 1.0
            SQL);
    }

    /**
     * PRD §16: basket level, source order_items directly, grouped by order_id (not customer_id — PRD's
     * own C5 resolution), min co-purchase 10. co_customers/a_customers/b_customers hold basket counts.
     */
    private function rebuildBasketLevel(): int
    {
        return DB::affectingStatement(<<<'SQL'
            WITH pairs AS (
                SELECT DISTINCT oi.order_id, oi.product_id
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                WHERE o.is_realized = true AND o.deleted_at IS NULL AND oi.product_id IS NOT NULL
            ),
            totals AS (
                SELECT product_id, COUNT(DISTINCT order_id) AS baskets FROM pairs GROUP BY product_id
            ),
            population AS (
                SELECT COUNT(DISTINCT order_id) AS total FROM pairs
            ),
            co AS (
                SELECT p1.product_id AS a_id, p2.product_id AS b_id, COUNT(DISTINCT p1.order_id) AS co_baskets
                FROM pairs p1
                JOIN pairs p2 ON p2.order_id = p1.order_id AND p2.product_id > p1.product_id
                GROUP BY p1.product_id, p2.product_id
                HAVING COUNT(DISTINCT p1.order_id) >= 10
            )
            INSERT INTO product_affinities (level, entity_a_id, entity_b_id, co_customers, a_customers, b_customers, support, confidence, lift, computed_at)
            SELECT
                'basket', co.a_id, co.b_id, co.co_baskets, ta.baskets, tb.baskets,
                co.co_baskets::numeric / population.total,
                co.co_baskets::numeric / ta.baskets,
                (co.co_baskets::numeric / ta.baskets) / (tb.baskets::numeric / population.total),
                NOW()
            FROM co
            JOIN totals ta ON ta.product_id = co.a_id
            JOIN totals tb ON tb.product_id = co.b_id
            CROSS JOIN population
            WHERE (co.co_baskets::numeric / ta.baskets) / (tb.baskets::numeric / population.total) > 1.0
            SQL);
    }
}
