<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Enums\OrderHistorySource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Models\Refund;
use Illuminate\Database\QueryException;

it('creates an order from the factory and belongs to a customer', function () {
    $order = Order::factory()->create()->refresh();

    expect($order->customer)->toBeInstanceOf(Customer::class)
        ->and($order->is_realized)->toBeFalse()
        ->and($order->is_fully_refunded)->toBeFalse()
        ->and($order->coupon_codes)->toBe([])
        ->and($order->ordered_at)->not->toBeNull();
});

it('casts every money field to int Toman', function () {
    $order = Order::factory()->create([
        'total' => 1_250_000, 'subtotal' => 1_300_000, 'discount_total' => 100_000,
        'shipping_total' => 50_000, 'tax_total' => 0, 'refunded_total' => 250_000,
    ])->refresh();

    foreach (['total', 'subtotal', 'discount_total', 'shipping_total', 'tax_total', 'refunded_total', 'net_revenue'] as $field) {
        expect($order->{$field})->toBeInt();
    }

    expect($order->net_revenue)->toBe(1_000_000);
});

it('exposes the database-generated net_revenue and never lets it be mass assigned', function () {
    $order = Order::factory()->create(['total' => 500_000, 'refunded_total' => 0])->refresh();

    expect($order->net_revenue)->toBe(500_000);

    $order->update(['refunded_total' => 200_000]);
    expect($order->refresh()->net_revenue)->toBe(300_000);

    $mass = Order::query()->create([
        'woo_order_id' => 424242, 'customer_id' => $order->customer_id, 'status' => 'processing',
        'ordered_at' => now(), 'total' => 100, 'net_revenue' => 999_999,
    ]);

    expect($mass->refresh()->net_revenue)->toBe(100);
});

it('models a partial refund: order stays not-fully-refunded and net revenue shrinks', function () {
    $order = Order::factory()->create(['total' => 1_000_000]);
    $item = $order->items()->create(['name_snapshot' => 'Coat', 'qty' => 2, 'unit_price' => 500_000, 'line_subtotal' => 1_000_000, 'line_total' => 1_000_000]);

    $order->refunds()->create(['woo_refund_id' => 1, 'amount' => 500_000, 'refunded_at' => now()]);
    $item->update(['refunded_qty' => 1, 'refunded_amount' => 500_000]);
    $order->update(['refunded_total' => $order->refunds()->sum('amount')]);

    $order->refresh();

    expect($order->refunds->first()->is_full)->toBeFalse()
        ->and($order->is_fully_refunded)->toBeFalse()
        ->and($order->refunded_total)->toBe(500_000)
        ->and($order->net_revenue)->toBe(500_000)
        ->and($item->refresh()->refunded_qty)->toBe(1)->and($item->refunded_amount)->toBe(500_000);
});

it('models a full refund: refund flagged full, order fully refunded, net revenue zero', function () {
    $order = Order::factory()->create(['total' => 800_000]);

    $order->refunds()->create(['woo_refund_id' => 9, 'amount' => 800_000, 'is_full' => true, 'reason' => 'لغو', 'refunded_at' => now()]);
    $order->update(['refunded_total' => 800_000, 'is_fully_refunded' => true]);

    $order->refresh();

    expect($order->refunds->first()->is_full)->toBeTrue()
        ->and($order->refunds->first()->amount)->toBeInt()->toBe(800_000)
        ->and($order->is_fully_refunded)->toBeTrue()
        ->and($order->net_revenue)->toBe(0);
});

it('keeps an order with a custom Woo status untouched (status is data, not an app-side enum)', function () {
    $order = Order::factory()->create(['status' => 'wc-custom-shipped'])->refresh();

    expect($order->status)->toBe('wc-custom-shipped');
});

it('records status transitions in order with a typed source', function () {
    $order = Order::factory()->create();

    $order->statusHistory()->create(['to_status' => 'pending', 'changed_at' => now()->subHour()]);
    $order->statusHistory()->create(['from_status' => 'pending', 'to_status' => 'processing', 'changed_at' => now()]);

    $history = $order->statusHistory()->orderBy('changed_at')->get();

    expect($history)->toHaveCount(2)
        ->and($history[0]->from_status)->toBeNull()
        ->and($history[1]->from_status)->toBe('pending')
        ->and($history[1]->refresh()->source)->toBe(OrderHistorySource::Sync)
        ->and(OrderStatusHistory::query()->first()->order->is($order))->toBeTrue();
});

it('stores items whose catalog references are only ids — Orders never loads Catalog models', function () {
    $order = Order::factory()->create();

    $item = $order->items()->create(['woo_item_id' => 3, 'sku' => 'NOPE', 'name_snapshot' => 'Unknown item', 'qty' => 3, 'unit_price' => 10, 'line_subtotal' => 30, 'line_total' => 30])->refresh();

    expect($item->product_id)->toBeNull()->and($item->variation_id)->toBeNull()
        ->and($item->qty)->toBe(3)->and($item->line_total)->toBeInt()
        ->and(method_exists(OrderItem::class, 'product'))->toBeFalse()
        ->and(method_exists(OrderItem::class, 'variation'))->toBeFalse()
        ->and(OrderItem::query()->first()->order->is($order))->toBeTrue();
});

it('soft deletes an order and keeps its items, history and refunds', function () {
    $order = Order::factory()->create();
    $order->items()->create(['name_snapshot' => 'x']);
    $order->refunds()->create(['woo_refund_id' => 1, 'amount' => 1, 'refunded_at' => now()]);

    $order->delete();

    expect(Order::query()->count())->toBe(0)
        ->and(Order::withTrashed()->count())->toBe(1)
        ->and(OrderItem::query()->count())->toBe(1)
        ->and(Refund::query()->count())->toBe(1);
});

it('will not let a customer with orders be hard deleted', function () {
    $order = Order::factory()->create();

    $order->customer->forceDelete();
})->throws(QueryException::class);
