<?php

declare(strict_types=1);

use App\Modules\Sync\DTOs\RefundDto;
use App\Modules\Sync\DTOs\RefundItemDto;
use App\Modules\Sync\Exceptions\WooMappingException;
use App\Modules\Sync\Mappers\RefundMapper;
use Tests\Support\WooPayloads;

/*
| Raw Woo refund payload -> RefundDto. Woo sends refund totals/quantities NEGATIVE; the DTO carries
| positive magnitudes. Deciding is_full, summing refunded_total, or touching order items is P2-07
| (refunded_total is RECOMPUTED from refunds, never incremented — CLAUDE.md §3); none of it happens here.
*/

function refundFixtures(): array
{
    return WooPayloads::items('orders/5001/refunds');
}

function mapRefund(array $payload, int $orderId = 5001): RefundDto
{
    return (new RefundMapper)->map($payload, $orderId);
}

it('maps an item-level refund: positive amount and magnitudes, order id from the endpoint', function () {
    $refund = mapRefund(refundFixtures()[0]);
    $item = $refund->items[0];

    expect($refund->wooRefundId)->toBe(7001)
        ->and($refund->wooOrderId)->toBe(5001)
        ->and($refund->amount)->toBe(100000)
        ->and($refund->reason)->toBe('سایز مناسب نبود')
        ->and($refund->refundedAt->format('Y-m-d H:i:s'))->toBe('2026-05-14 10:00:00')
        ->and($refund->items)->toHaveCount(1)
        ->and($item)->toBeInstanceOf(RefundItemDto::class)
        ->and($item->wooItemId)->toBe(9101)
        ->and($item->wooProductId)->toBe(101)
        ->and($item->wooVariationId)->toBeNull()
        ->and($item->sku)->toBe('SYN-TEE-001')
        ->and($item->quantity)->toBe(1)
        ->and($item->amount)->toBe(100000)
        ->and($item->originalWooItemId)->toBe(9001);
});

it('maps an amount-only refund: empty reason is none, no items', function () {
    $refund = mapRefund(refundFixtures()[1]);

    expect($refund->wooRefundId)->toBe(7002)
        ->and($refund->amount)->toBe(50000)
        ->and($refund->reason)->toBeNull()
        ->and($refund->items)->toBe([]);
});

it('never reports a negative amount or quantity, and only integers', function () {
    foreach (refundFixtures() as $raw) {
        $refund = mapRefund($raw);
        $numbers = [$refund->amount];

        foreach ($refund->items as $item) {
            array_push($numbers, $item->quantity, $item->amount);
        }

        expect(array_filter($numbers, fn ($n) => ! is_int($n) || $n < 0))->toBe([]);
    }
});

it('does not decide is_full, sum anything across refunds, or touch the payload', function () {
    $payload = refundFixtures()[0];
    $before = $payload;

    $dto = mapRefund($payload);

    expect($payload)->toBe($before)
        ->and((new ReflectionClass($dto))->hasProperty('isFull'))->toBeFalse();
});

it('fails loudly, naming the field, when a required refund field is missing or malformed', function (string $path, mixed $value, bool $remove) {
    $raw = refundFixtures()[0];
    $raw = $remove ? WooPayloads::without($raw, $path) : WooPayloads::set($raw, $path, $value);

    try {
        mapRefund($raw);
    } catch (WooMappingException $e) {
        expect($e->entity)->toBe('refund')->and($e->field)->toBe($path);

        return;
    }

    $this->fail("Expected WooMappingException at {$path}");
})->with([
    'no id' => ['id', null, true],
    'id as string' => ['id', '7001', false],
    'no amount' => ['amount', null, true],
    'negative amount' => ['amount', '-100000', false],
    'amount with decimals' => ['amount', '10.5', false],
    'no date' => ['date_created_gmt', null, true],
    'malformed date' => ['date_created_gmt', '2026-05-14', false],
    'no line_items key' => ['line_items', null, true],
    'line_items not a list' => ['line_items', 'x', false],
    'item without id' => ['line_items.0.id', null, true],
    'item quantity as string' => ['line_items.0.quantity', '-1', false],
    'item total missing' => ['line_items.0.total', null, true],
    'item total malformed' => ['line_items.0.total', 'abc', false],
    'reason is a number' => ['reason', 5, false],
]);

it('rejects an order id that is not a real id — a caller bug, not payload data', function (int $orderId) {
    mapRefund(refundFixtures()[0], $orderId);
})->with([[0], [-1]])->throws(InvalidArgumentException::class);

// ------------------------------------------------ the link to the original order item

/** The recorded refund line, with its meta_data replaced. */
function refundWithMeta(mixed $meta): array
{
    return WooPayloads::set(refundFixtures()[0], 'line_items.0.meta_data', $meta);
}

it('reads the original order item from meta _refunded_item_id — Woo sends it as a string', function (mixed $value) {
    $item = mapRefund(refundWithMeta([['id' => 1, 'key' => '_refunded_item_id', 'value' => $value]]))->items[0];

    expect($item->originalWooItemId)->toBe(9001);
})->with([['9001'], [9001]]);

it('ignores unrelated meta entries and finds the link among them', function () {
    $item = mapRefund(refundWithMeta([
        ['id' => 1, 'key' => '_reduced_stock', 'value' => 'not an id'],
        ['id' => 2, 'key' => '_refunded_item_id', 'value' => '9002'],
    ]))->items[0];

    expect($item->originalWooItemId)->toBe(9002);
});

it('leaves a line UNATTRIBUTED (null) when Woo gave no link — it never guesses from product, variation or SKU', function (mixed $meta) {
    $raw = $meta === '__absent__' ? WooPayloads::without(refundFixtures()[0], 'line_items.0.meta_data') : refundWithMeta($meta);

    $item = mapRefund($raw)->items[0];

    expect($item->originalWooItemId)->toBeNull()
        ->and([$item->wooProductId, $item->sku])->toBe([101, 'SYN-TEE-001']);
})->with([
    'no meta_data at all' => ['__absent__'],
    'null meta_data' => [null],
    'empty meta_data' => [[]],
    'only other keys' => [[['id' => 1, 'key' => '_reduced_stock', 'value' => '1']]],
]);

it('fails loudly on a malformed link instead of treating it as no link', function (mixed $meta, string $field) {
    try {
        mapRefund(refundWithMeta($meta));
    } catch (WooMappingException $e) {
        expect($e->entity)->toBe('refund')->and($e->field)->toBe($field);

        return;
    }

    $this->fail("Expected WooMappingException at {$field}");
})->with([
    'non-numeric value' => [[['id' => 1, 'key' => '_refunded_item_id', 'value' => 'abc']], 'line_items.0.meta_data.0.value'],
    'zero value' => [[['id' => 1, 'key' => '_refunded_item_id', 'value' => '0']], 'line_items.0.meta_data.0.value'],
    'array value' => [[['id' => 1, 'key' => '_refunded_item_id', 'value' => ['9001']]], 'line_items.0.meta_data.0.value'],
    'value missing' => [[['id' => 1, 'key' => '_refunded_item_id']], 'line_items.0.meta_data.0.value'],
    'the same link twice' => [[['id' => 1, 'key' => '_refunded_item_id', 'value' => '9001'], ['id' => 2, 'key' => '_refunded_item_id', 'value' => '9002']], 'line_items.0.meta_data.1.key'],
    'entry without a key' => [[['id' => 1, 'value' => '9001']], 'line_items.0.meta_data.0.key'],
    'meta_data not a list' => ['nope', 'line_items.0.meta_data'],
]);
