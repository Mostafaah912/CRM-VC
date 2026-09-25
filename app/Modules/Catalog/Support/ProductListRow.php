<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Support\TehranDateTime;
use Carbon\CarbonImmutable;

/**
 * One product as the list shows it: the product's own columns, a representative SKU (see ProductListService), and its lifetime
 * sales from realized orders only. Built from a plain array (a cast query-builder row), never Eloquent's Order or OrderItem —
 * the Catalog module never `use`s a class of Orders (ArchitectureTest); reading their tables this way is the same documented
 * data dependency P3-03/P3-06 use elsewhere.
 *
 * A product with no realized sale has never been LEFT JOINed to a sales row: total_qty_sold, total_revenue and order_count are
 * then 0 (never null — they are counts/sums, and "zero sales" is a fact, not a missing value); last_sold_at stays null, which the
 * page reads as "هنوز فروش نداشته".
 *
 * `admin_url` is the WooCommerce admin edit link, built here (never on the browser, and never from a value the browser sends) —
 * `config('woo.base_url')` is not a credential, and every viewer of this page already holds `catalog.view` on this same store,
 * but building it server-side keeps the store's base URL out of any client-side code. It is null when the store has none
 * configured (the page then shows the name as plain text).
 *
 * @phpstan-type ProductListShape array{id: int, woo_product_id: int, name: string, sku: string|null, status: string, admin_url: string|null, total_qty_sold: int, total_revenue: int, order_count: int, last_sold_at_jalali: string|null, last_sold_at_iso: string|null}
 */
final readonly class ProductListRow
{
    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    /** @param array<string, mixed> $row */
    private function __construct(private array $row) {}

    /** @return ProductListShape */
    public function toArray(): array
    {
        $r = $this->row;
        $lastSoldAt = $r['last_sold_at'] === null ? null : CarbonImmutable::parse((string) $r['last_sold_at'], 'UTC');
        $wooProductId = (int) $r['woo_product_id'];
        $baseUrl = (string) config('woo.base_url');

        return [
            'id' => (int) $r['id'],
            'woo_product_id' => $wooProductId,
            'name' => (string) $r['name'],
            'sku' => $r['sku'] === null ? null : (string) $r['sku'],
            'status' => (string) $r['status'],
            'admin_url' => $baseUrl === '' ? null : rtrim($baseUrl, '/')."/wp-admin/post.php?post={$wooProductId}&action=edit",
            'total_qty_sold' => (int) ($r['total_qty_sold'] ?? 0),
            // Money is int Toman, exactly as stored — the store's unit is Toman already (P0-00), so there is nothing to convert.
            'total_revenue' => (int) ($r['total_revenue'] ?? 0),
            'order_count' => (int) ($r['order_count'] ?? 0),
            'last_sold_at_jalali' => $lastSoldAt === null ? null : TehranDateTime::format($lastSoldAt),
            'last_sold_at_iso' => $lastSoldAt === null ? null : $lastSoldAt->utc()->toIso8601ZuluString(),
        ];
    }
}
