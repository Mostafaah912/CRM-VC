<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Modules\Orders\Services\OrderInput;
use App\Modules\Orders\Services\OrderItemInput;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Sync\DTOs\OrderDto;
use App\Modules\Sync\DTOs\OrderItemDto;
use App\Modules\Sync\Exceptions\WooCurrencyMismatchException;
use App\Modules\Sync\Mappers\OrderMapper;
use App\Modules\Sync\Support\OrderSyncResult;
use App\Modules\Sync\Support\SyncWindow;

/**
 * Woo -> Orders (P2-06): reads ONE page of orders through the WooClient contract, turns each raw payload into a
 * DTO with the P2-03 mapper, checks its currency, and hands a typed input to Orders' public OrderService. No HTTP,
 * no models, no database, no identity or status logic here. Paging, cursors, jobs and refunds are P2-08/P2-07:
 * the caller chooses the page and may pass a frozen window straight through.
 *
 * Each order is its own transaction (inside OrderService). A Woo, mapping, currency or customer failure stops the
 * page and propagates; orders already committed stay, and syncing the page again is safe (idempotent).
 */
final class OrderSyncService
{
    public function __construct(
        private readonly WooClient $woo,
        private readonly OrderService $orders,
        private readonly OrderMapper $mapper = new OrderMapper,
    ) {}

    public function syncPage(int $page = 1, ?SyncWindow $window = null): OrderSyncResult
    {
        $wooPage = $this->woo->page('orders', $page, $window);
        $orders = 0;
        $items = 0;

        foreach ($wooPage->items as $raw) {
            $dto = $this->mapper->map($raw);
            $this->assertStoreCurrency($dto);

            $this->orders->upsert($this->input($dto));

            $orders++;
            $items += count($dto->items);
        }

        return new OrderSyncResult($orders, $items, $wooPage->hasMore());
    }

    private function assertStoreCurrency(OrderDto $dto): void
    {
        $expected = (string) config('woo.currency');

        if ($dto->currency !== $expected) {
            throw new WooCurrencyMismatchException($dto->wooOrderId, $dto->currency, $expected);
        }
    }

    private function input(OrderDto $o): OrderInput
    {
        return new OrderInput(
            $o->wooOrderId, $o->number, $o->status, $o->wooCustomerId,
            $o->billingFirstName, $o->billingLastName, $o->billingPhone,
            $o->total, $o->discountTotal, $o->shippingTotal, $o->taxTotal,
            $o->couponCodes, $o->paymentMethod,
            $o->orderedAt, $o->paidAt, $o->completedAt, $o->wooModifiedAt,
            array_map(fn (OrderItemDto $i) => new OrderItemInput(
                $i->wooItemId, $i->wooProductId, $i->wooVariationId, $i->sku, $i->name,
                $i->quantity, $i->unitPrice, $i->lineSubtotal, $i->lineTotal,
            ), $o->items),
        );
    }
}
