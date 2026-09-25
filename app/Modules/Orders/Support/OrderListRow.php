<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Support\TehranDateTime;
use Carbon\CarbonImmutable;

/**
 * One order as the list shows it: the order's own columns plus the customer's display name (LEFT JOIN — an order without a
 * customer, `needs_phone_review`, is not dropped). Built from a plain array (a cast query-builder row), never an Eloquent
 * Customer, so this stays inside the Orders module's own reach (ArchitectureTest: reading the `customers` table is fine,
 * `use`ing its Model is not).
 *
 * @phpstan-type OrderListShape array{id: int, woo_order_id: int, status: string, total: int, is_realized: bool, needs_phone_review: bool, ordered_at_jalali: string, ordered_at_iso: string, customer_id: int|null, customer_display_name: string|null}
 */
final readonly class OrderListRow
{
    /** The columns this list selects, qualified: orders.* plus the one customers column it needs. */
    public const COLUMNS = [
        'orders.id', 'orders.woo_order_id', 'orders.status', 'orders.total', 'orders.is_realized',
        'orders.needs_phone_review', 'orders.ordered_at', 'orders.customer_id', 'customers.display_name as customer_display_name',
    ];

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    /** @param array<string, mixed> $row */
    private function __construct(private array $row) {}

    /** @return OrderListShape */
    public function toArray(): array
    {
        $r = $this->row;
        $at = CarbonImmutable::parse((string) $r['ordered_at'], 'UTC');

        return [
            'id' => (int) $r['id'],
            'woo_order_id' => (int) $r['woo_order_id'],
            'status' => (string) $r['status'],
            // Money is int Toman, exactly as stored — the store's unit is Toman already (P0-00), so there is nothing to convert.
            'total' => (int) $r['total'],
            'is_realized' => (bool) $r['is_realized'],
            'needs_phone_review' => (bool) $r['needs_phone_review'],
            'ordered_at_jalali' => TehranDateTime::format($at),
            'ordered_at_iso' => $at->utc()->toIso8601ZuluString(),
            'customer_id' => $r['customer_id'] === null ? null : (int) $r['customer_id'],
            // No customer_id means no LEFT JOIN match, so this is always null then — never a stray name from a coincidental join.
            'customer_display_name' => $r['customer_id'] === null ? null : $r['customer_display_name'],
        ];
    }
}
