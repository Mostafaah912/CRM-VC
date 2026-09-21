<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Support\ProductListFilters;
use App\Modules\Catalog\Support\ProductListRow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * P3-07, read-only: the product list with lifetime sales. `products` LEFT JOIN a per-product sales aggregate (from `order_items`
 * JOIN `orders` WHERE `orders.is_realized = true`, grouped by product_id) LEFT JOIN a representative SKU (a product's first
 * variation by id — every product, simple or variable, has at least one row in `product_variations`; a variable product's several
 * SKUs are not collapsed into one, so this is a representative, not necessarily unique, value). Both are read with the query
 * builder — `joinSub`/`selectSub`, not a hand-built SQL string — and never `use` a class of Orders (ArchitectureTest); reading
 * their tables this way is the same documented data dependency P3-03/P3-06 use elsewhere.
 *
 * ⚠ Rule 7 (ArchitectureTest: raw SQL confined to Migrations/Metrics/Analytics) is amended for this ONE file, ONE query
 * (ARCHITECTURE.md, "P3-07 — استثنای Rule 7 برای Aggregate فروش Catalog"): grouped SUM/COUNT/MAX in one row has no query-builder
 * form that avoids `selectRaw`/`orderByRaw` in Laravel, and CLAUDE.md §3 requires heavy analytics to be one SQL statement, never a
 * PHP loop over rows — computing this store-wide (every realized order line, not one customer's) in PHP would violate that.
 * Every raw fragment here is a static string literal: no interpolated value, no request input, ever reaches one.
 *
 * Sort is fixed: total_revenue DESC (top sellers first, NULLS treated as 0 so a never-sold product sorts last, not first —
 * Postgres's default is NULLS FIRST on DESC), then id DESC. Offset pagination, 25 a page.
 *
 * @phpstan-import-type ProductListShape from ProductListRow
 */
final class ProductListService
{
    private const PER_PAGE = 25;

    /** @return LengthAwarePaginator<int, ProductListShape> */
    public function paginate(ProductListFilters $filters, int $page = 1, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $this->query($filters)
            ->orderByRaw('COALESCE(sales.total_revenue, 0) DESC, products.id DESC')
            ->paginate($perPage, [
                'products.id', 'products.woo_product_id', 'products.name', 'products.status',
                'sku.sku', 'sales.total_qty_sold', 'sales.total_revenue', 'sales.order_count', 'sales.last_sold_at',
            ], 'page', $page)
            ->withQueryString()
            ->through(fn (object $row): array => ProductListRow::fromRow((array) $row)->toArray());
    }

    private function query(ProductListFilters $filters): Builder
    {
        return DB::table('products')
            ->leftJoinSub($this->salesSubquery(), 'sales', 'sales.product_id', '=', 'products.id')
            ->leftJoinSub($this->skuSubquery(), 'sku', 'sku.product_id', '=', 'products.id')
            ->when($filters->name !== null, fn (Builder $query) => $query->where('products.name', 'ilike', '%'.$this->escapeLike($filters->name).'%'))
            ->when($filters->sku !== null, fn (Builder $query) => $query->whereExists(
                fn (Builder $exists) => $exists->selectRaw('1')
                    ->from('product_variations')
                    ->whereColumn('product_variations.product_id', 'products.id')
                    ->where('product_variations.sku', $filters->sku),
            ))
            ->when($filters->status !== null, fn (Builder $query) => $query->where('products.status', $filters->status->value));
    }

    /** One grouped row per product with a realized sale: total quantity, revenue, distinct order count, and the last sale. */
    private function salesSubquery(): Builder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.is_realized', true)
            ->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.qty) as total_qty_sold, SUM(order_items.line_total) as total_revenue, COUNT(DISTINCT order_items.order_id) as order_count, MAX(orders.ordered_at) as last_sold_at');
    }

    /** The lowest-id variation of each product with a SKU, as a stand-in: exact for a simple product (it has exactly one). */
    private function skuSubquery(): Builder
    {
        $firstVariationId = DB::table('product_variations')
            ->whereNotNull('sku')
            ->groupBy('product_id')
            ->select('product_id')
            ->selectRaw('MIN(id) as variation_id');

        return DB::table('product_variations')
            ->joinSub($firstVariationId, 'first_sku', fn (JoinClause $join) => $join
                ->on('product_variations.product_id', '=', 'first_sku.product_id')
                ->on('product_variations.id', '=', 'first_sku.variation_id'))
            ->select(['product_variations.product_id', 'product_variations.sku']);
    }

    /**
     * Every ProductStatus value, for the filter form.
     *
     * @return list<string>
     */
    public function statusOptions(): array
    {
        return array_map(fn (ProductStatus $status): string => $status->value, ProductStatus::cases());
    }

    /** `\`, `%` and `_` are LIKE syntax: typed by a user they are just characters. */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
