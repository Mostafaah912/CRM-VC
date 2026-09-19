<?php

declare(strict_types=1);

use App\Modules\Sync\DTOs\OrderDto;
use App\Modules\Sync\DTOs\OrderItemDto;
use App\Modules\Sync\Exceptions\WooMappingException;
use App\Modules\Sync\Mappers\OrderMapper;
use Tests\Support\WooPayloads;

/*
| Raw Woo order payload -> OrderDto. Pure transformation: no identity resolution (the phone stays
| exactly as Woo sent it — PhoneNormalizer belongs to P2-04), no DB, no status classification
| (the status stays Woo's raw slug — is_realized is OrderStatusMapper's job at persist time).
| Payloads are the P2-02 synthetic fixtures; they prove the contract, not real business data.
*/

function mapOrder(array $payload): OrderDto
{
    return (new OrderMapper)->map($payload);
}

function orderFixture(int $index = 0, int $page = 1): array
{
    return WooPayloads::items('orders', $page)[$index];
}

it('maps every field of a completed, paid, discounted-free order', function () {
    $order = mapOrder(orderFixture(0));

    expect($order)->toBeInstanceOf(OrderDto::class)
        ->and($order->wooOrderId)->toBe(5001)
        ->and($order->number)->toBe('5001')
        ->and($order->status)->toBe('completed')
        ->and($order->currency)->toBe('IRT')
        ->and($order->wooCustomerId)->toBe(11)
        ->and($order->billingFirstName)->toBe('مشتری')
        ->and($order->billingLastName)->toBe('آزمایشی یک')
        ->and($order->billingPhone)->toBe('09000000001')
        ->and($order->total)->toBe(403880)
        ->and($order->discountTotal)->toBe(0)
        ->and($order->shippingTotal)->toBe(0)
        ->and($order->taxTotal)->toBe(0)
        ->and($order->couponCodes)->toBe(['synth10'])
        ->and($order->paymentMethod)->toBe('synthetic_gateway')
        ->and($order->orderedAt->format('Y-m-d H:i:s'))->toBe('2026-05-10 08:30:00')
        ->and($order->paidAt?->format('Y-m-d H:i:s'))->toBe('2026-05-10 08:35:00')
        ->and($order->completedAt?->format('Y-m-d H:i:s'))->toBe('2026-05-12 09:00:00')
        ->and($order->wooModifiedAt->format('Y-m-d H:i:s'))->toBe('2026-05-12 09:00:00')
        ->and($order->orderedAt->getTimezone()->getName())->toBe('UTC')
        ->and($order->items)->toHaveCount(1);
});

it('maps its line items with ids, sku, name, quantity and every amount', function () {
    $item = mapOrder(orderFixture(0))->items[0];

    expect($item)->toBeInstanceOf(OrderItemDto::class)
        ->and($item->wooItemId)->toBe(9001)
        ->and($item->wooProductId)->toBe(101)
        ->and($item->wooVariationId)->toBeNull()
        ->and($item->sku)->toBe('SYN-TEE-001')
        ->and($item->name)->toBe('تی‌شرت آزمایشی')
        ->and($item->quantity)->toBe(1)
        ->and($item->unitPrice)->toBe(403880)
        ->and($item->lineSubtotal)->toBe(403880)
        ->and($item->lineTotal)->toBe(403880);
});

it('keeps a variation id and a discount: subtotal and total are different amounts', function () {
    $order = mapOrder(orderFixture(1));
    $item = $order->items[0];

    expect($order->status)->toBe('processing')
        ->and($order->discountTotal)->toBe(50000)
        ->and($order->total)->toBe(1250000)
        ->and($item->wooVariationId)->toBe(1021)
        ->and($item->sku)->toBe('SYN-JKT-002-M')
        ->and($item->unitPrice)->toBe(1250000)
        ->and($item->lineSubtotal)->toBe(1300000)
        ->and($item->lineTotal)->toBe(1250000)
        ->and($order->completedAt)->toBeNull();
});

it('leaves the phone exactly as Woo sent it — no normalisation, no identity work', function () {
    $sent = orderFixture(1)['billing']['phone'];

    expect($sent)->toStartWith("\u{200F}")
        ->and(mapOrder(orderFixture(1))->billingPhone)->toBe($sent);
});

it('keeps an order whose product no longer exists: null ids and sku, name snapshot preserved', function () {
    $order = mapOrder(orderFixture(0, page: 2));
    $item = $order->items[0];

    expect($order->status)->toBe('cancelled')
        ->and($order->paidAt)->toBeNull()
        ->and($order->couponCodes)->toBe([])
        ->and($order->paymentMethod)->toBe('cod')
        ->and($item->wooProductId)->toBeNull()
        ->and($item->wooVariationId)->toBeNull()
        ->and($item->sku)->toBeNull()
        ->and($item->name)->toBe('محصول حذف‌شده آزمایشی')
        ->and($item->quantity)->toBe(2)
        ->and($item->lineTotal)->toBe(560000);
});

it('maps every recorded order, filtered and unfiltered, without error', function () {
    $orders = array_merge(
        WooPayloads::items('orders', 1),
        WooPayloads::items('orders', 2),
        WooPayloads::items('orders', 1, ['status' => 'completed']),
    );

    expect(array_map(fn (array $raw) => mapOrder($raw)->wooOrderId, $orders))->toBe([5001, 5002, 5003, 5001]);
});

it('produces integer money only, never a float', function () {
    foreach (WooPayloads::items('orders', 1) as $raw) {
        $order = mapOrder($raw);
        $amounts = [$order->total, $order->discountTotal, $order->shippingTotal, $order->taxTotal];

        foreach ($order->items as $item) {
            array_push($amounts, $item->unitPrice, $item->lineSubtotal, $item->lineTotal);
        }

        expect(array_filter($amounts, fn ($a) => ! is_int($a)))->toBe([]);
    }
});

it('passes the raw status through untouched: no mapping, no case folding, no prefix stripping', function (string $status) {
    expect(mapOrder(WooPayloads::set(orderFixture(0), 'status', $status))->status)->toBe($status);
})->with(['refunded', 'on-hold', 'a-custom-store-status', 'wc-completed', 'Completed']);

it('reads a guest order (customer_id 0) as no Woo customer, and a blank phone as no phone', function () {
    $order = mapOrder(WooPayloads::set(WooPayloads::set(orderFixture(0), 'customer_id', 0), 'billing.phone', ''));

    expect($order->wooCustomerId)->toBeNull()->and($order->billingPhone)->toBeNull();
});

it('treats an order without coupon_lines, or without a payment method, as having none', function () {
    $order = mapOrder(WooPayloads::without(WooPayloads::set(orderFixture(0), 'payment_method', ''), 'coupon_lines'));

    expect($order->couponCodes)->toBe([])->and($order->paymentMethod)->toBeNull();
});

it('accepts an order with no line items', function () {
    expect(mapOrder(WooPayloads::set(orderFixture(0), 'line_items', []))->items)->toBe([]);
});

it('does not mutate or depend on anything but the payload it was given', function () {
    $payload = orderFixture(1);
    $before = $payload;

    $first = mapOrder($payload);
    $second = mapOrder($payload);

    expect($payload)->toBe($before)->and($second)->toEqual($first);
});

// ------------------------------------------------------- required fields

it('fails loudly, naming the field, when a required field is missing', function (string $path) {
    try {
        mapOrder(WooPayloads::without(orderFixture(0), $path));
    } catch (WooMappingException $e) {
        expect($e->entity)->toBe('order')->and($e->field)->toBe($path)->and($e->getMessage())->toContain($path);

        return;
    }

    $this->fail("Expected WooMappingException for missing {$path}");
})->with([
    'id', 'status', 'currency', 'customer_id', 'total', 'discount_total', 'shipping_total', 'total_tax',
    'date_created_gmt', 'date_modified_gmt', 'billing', 'billing.first_name', 'billing.last_name',
    'line_items', 'line_items.0.id', 'line_items.0.name', 'line_items.0.quantity',
    'line_items.0.price', 'line_items.0.subtotal', 'line_items.0.total',
]);

it('fails loudly, naming the field, when a value is malformed', function (string $path, mixed $value) {
    try {
        mapOrder(WooPayloads::set(orderFixture(0), $path, $value));
    } catch (WooMappingException $e) {
        expect($e->field)->toBe($path);

        return;
    }

    $this->fail("Expected WooMappingException for malformed {$path}");
})->with([
    'id as string' => ['id', '5001'],
    'id zero' => ['id', 0],
    'blank status' => ['status', ''],
    'int status' => ['status', 5],
    'total with decimals' => ['total', '403880.5'],
    'negative total' => ['total', '-5'],
    'non-numeric total' => ['total', 'abc'],
    'float discount' => ['discount_total', 1.5],
    'null shipping' => ['shipping_total', null],
    'created date wrong format' => ['date_created_gmt', '2026-05-10 08:30:00'],
    'modified date impossible' => ['date_modified_gmt', '2026-02-31T00:00:00'],
    'paid date garbage, not null' => ['date_paid_gmt', 'garbage'],
    'completed date blank, not null' => ['date_completed_gmt', ''],
    'customer id string' => ['customer_id', '11'],
    'billing is a string' => ['billing', 'x'],
    'phone is a number' => ['billing.phone', 9121234567],
    'line_items is a string' => ['line_items', 'x'],
    'line item is a scalar' => ['line_items.0', 5],
    'item quantity string' => ['line_items.0.quantity', '1'],
    'item quantity negative' => ['line_items.0.quantity', -1],
    'item total decimals' => ['line_items.0.total', '10.5'],
    'item price fractional' => ['line_items.0.price', 403880.5],
    'item product id string' => ['line_items.0.product_id', '101'],
    'item sku number' => ['line_items.0.sku', 12345],
    'coupon code null' => ['coupon_lines.0.code', null],
    'coupon code blank' => ['coupon_lines.0.code', ''],
]);

it('names the value type but never the value when it fails (no PII in errors)', function () {
    try {
        mapOrder(WooPayloads::set(orderFixture(0), 'total', '09121234567'));
    } catch (WooMappingException $e) {
        expect($e->getMessage())->not->toContain('09121234567');

        return;
    }

    $this->fail('Expected WooMappingException');
});

it('does not swallow a broken second line item behind a good first one', function () {
    $payload = orderFixture(0);
    $payload['line_items'][] = WooPayloads::without($payload['line_items'][0], 'name');

    try {
        mapOrder($payload);
    } catch (WooMappingException $e) {
        expect($e->field)->toBe('line_items.1.name');

        return;
    }

    $this->fail('Expected WooMappingException');
});

// ------------------------------------------------------------------ refundsCount (P2-08 refund discovery)

it('reads how many refunds Woo says the order has: the length of the payload\'s refunds list', function () {
    $payload = WooPayloads::set(orderFixture(0), 'refunds', [
        ['id' => 7001, 'reason' => '', 'total' => '-100000'],
        ['id' => 7002, 'reason' => '', 'total' => '-50000'],
    ]);

    expect(mapOrder($payload)->refundsCount)->toBe(2);
});

it('maps the recorded orders, which list no refunds, to 0', function () {
    expect(mapOrder(orderFixture(0))->refundsCount)->toBe(0)
        ->and(mapOrder(orderFixture(1))->refundsCount)->toBe(0);
});

it('keeps refundsCount null — unknown, never 0 — when the payload has no refunds key or a null one', function () {
    expect(mapOrder(WooPayloads::without(orderFixture(0), 'refunds'))->refundsCount)->toBeNull()
        ->and(mapOrder(WooPayloads::set(orderFixture(0), 'refunds', null))->refundsCount)->toBeNull();
});

it('rejects a refunds value that is not a list of objects, naming the field and never the value', function () {
    try {
        mapOrder(WooPayloads::set(orderFixture(0), 'refunds', '09121234567'));
    } catch (WooMappingException $e) {
        expect($e->field)->toBe('refunds')->and($e->getMessage())->not->toContain('09121234567');

        return;
    }

    $this->fail('Expected WooMappingException');
});

it('does not let refunds decide anything else about the order', function () {
    $with = mapOrder(WooPayloads::set(orderFixture(0), 'refunds', [['id' => 7001, 'reason' => '', 'total' => '-100000']]));
    $without = mapOrder(orderFixture(0));

    expect([...(array) $with, 'refundsCount' => null])->toEqual([...(array) $without, 'refundsCount' => null]);
});
