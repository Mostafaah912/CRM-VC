<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Support\PhoneMask;
use App\Support\TehranDateTime;
use Carbon\CarbonImmutable;

/**
 * One order's detail page. Built from plain arrays (each a cast query-builder row — never Eloquent's Customer or Order models:
 * the Orders module reads the `orders`, `order_items` and `customers` tables directly; ArchitectureTest bans a cross-module
 * `use`, not a query).
 *
 * The customer's phone is ALWAYS masked here, for every viewer — the same rule as everywhere else a customer appears (P3-01,
 * P3-02, P3-03): there is no full-number path except PhoneRevealService's own audited endpoint, which the page's
 * PhoneRevealButton calls one number at a time. `profile_url` is the Customer 360 link (P3-03).
 *
 * @phpstan-type OrderItemShape array{name: string, sku: string|null, qty: int, unit_price: int, line_total: int}
 * @phpstan-type OrderCustomerShape array{id: int, display_name: string|null, phone_masked: string, profile_url: string}
 * @phpstan-type OrderShowShape array{id: int, woo_order_id: int, status: string, total: int, subtotal: int, discount_total: int, shipping_total: int, tax_total: int, refunded_total: int, is_realized: bool, is_fully_refunded: bool, needs_phone_review: bool, ordered_at_jalali: string, ordered_at_iso: string, items: list<OrderItemShape>, customer: OrderCustomerShape|null}
 */
final readonly class OrderShowData
{
    /**
     * @param  array<string, mixed>  $order  the orders row: id, woo_order_id, status, total, subtotal, discount_total, shipping_total, tax_total, refunded_total, is_realized, is_fully_refunded, needs_phone_review, ordered_at, customer_id
     * @param  list<array<string, mixed>>  $items  each: name_snapshot, sku, qty, unit_price, line_total
     * @param  array<string, mixed>|null  $customer  id, display_name, phone_normalized — null when the order has none
     */
    public function __construct(
        private array $order,
        private array $items,
        private ?array $customer,
    ) {}

    /** @return OrderShowShape */
    public function toArray(): array
    {
        $o = $this->order;
        $at = CarbonImmutable::parse((string) $o['ordered_at'], 'UTC');

        return [
            'id' => (int) $o['id'],
            'woo_order_id' => (int) $o['woo_order_id'],
            'status' => (string) $o['status'],
            'total' => (int) $o['total'],
            'subtotal' => (int) $o['subtotal'],
            'discount_total' => (int) $o['discount_total'],
            'shipping_total' => (int) $o['shipping_total'],
            'tax_total' => (int) $o['tax_total'],
            'refunded_total' => (int) $o['refunded_total'],
            'is_realized' => (bool) $o['is_realized'],
            'is_fully_refunded' => (bool) $o['is_fully_refunded'],
            'needs_phone_review' => (bool) $o['needs_phone_review'],
            'ordered_at_jalali' => TehranDateTime::format($at),
            'ordered_at_iso' => $at->utc()->toIso8601ZuluString(),
            'items' => array_map(fn (array $item): array => [
                'name' => (string) $item['name_snapshot'],
                'sku' => $item['sku'] === null ? null : (string) $item['sku'],
                'qty' => (int) $item['qty'],
                'unit_price' => (int) $item['unit_price'],
                'line_total' => (int) $item['line_total'],
            ], $this->items),
            'customer' => $this->customer === null ? null : [
                'id' => (int) $this->customer['id'],
                'display_name' => $this->customer['display_name'],
                'phone_masked' => PhoneMask::mask((string) $this->customer['phone_normalized']),
                'profile_url' => route('customers.show', (int) $this->customer['id']),
            ],
        ];
    }
}
