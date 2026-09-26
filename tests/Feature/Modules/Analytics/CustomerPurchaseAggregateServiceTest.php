<?php

declare(strict_types=1);

use App\Modules\Analytics\Services\CustomerPurchaseAggregateService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/*
| P6-01 (TEST FIRST): PRD §16's nightly aggregate tables, one INSERT...SELECT per table, rebuilt from
| scratch every run. No OrderItem factory exists yet (see ProductListControllerTest for precedent), so
| order_items are inserted directly via DB::table, matching the rest of the codebase's test style.
*/

function opOrder(Customer $customer, bool $isRealized = true): Order
{
    return Order::factory()->for($customer)->create(['is_realized' => $isRealized]);
}

function opItem(Order $order, ?Product $product, array $overrides = []): void
{
    DB::table('order_items')->insert(array_merge([
        'order_id' => $order->id,
        'product_id' => $product?->id,
        'name_snapshot' => $product?->name ?? 'ghost',
        'qty' => 1,
        'unit_price' => 100_000,
        'line_subtotal' => 100_000,
        'line_total' => 100_000,
        'refunded_amount' => 0,
    ], $overrides));
}

function productPurchaseRow(Customer $customer, Product $product): ?object
{
    return DB::table('customer_product_purchases')
        ->where('customer_id', $customer->id)->where('product_id', $product->id)->first();
}

it('excludes a non-realized order entirely', function () {
    $customer = Customer::factory()->create();
    $product = Product::factory()->create();
    opItem(opOrder($customer, isRealized: false), $product);

    app(CustomerPurchaseAggregateService::class)->rebuild();

    expect(productPurchaseRow($customer, $product))->toBeNull();
});

it('excludes a soft-deleted order entirely', function () {
    $customer = Customer::factory()->create();
    $product = Product::factory()->create();
    $order = opOrder($customer);
    opItem($order, $product);
    $order->delete();

    app(CustomerPurchaseAggregateService::class)->rebuild();

    expect(productPurchaseRow($customer, $product))->toBeNull();
});

it('excludes an order item whose product could not be resolved', function () {
    $customer = Customer::factory()->create();
    opItem(opOrder($customer), null);

    app(CustomerPurchaseAggregateService::class)->rebuild();

    expect(DB::table('customer_product_purchases')->where('customer_id', $customer->id)->exists())->toBeFalse()
        ->and(DB::table('customer_category_purchases')->where('customer_id', $customer->id)->exists())->toBeFalse();
});

it('nets a partial refund out of revenue, unlike Base Aggregates it does not exclude the order', function () {
    $customer = Customer::factory()->create();
    $product = Product::factory()->create();
    $order = opOrder($customer);
    opItem($order, $product, ['qty' => 2, 'line_total' => 500_000, 'refunded_amount' => 100_000]);

    app(CustomerPurchaseAggregateService::class)->rebuild();

    $row = productPurchaseRow($customer, $product);
    expect($row->orders_count)->toBe(1)
        ->and($row->items_count)->toBe(2)
        ->and($row->revenue)->toBe(400_000);
});

it('counts one order once even across two line items of the same product', function () {
    $customer = Customer::factory()->create();
    $product = Product::factory()->create();
    $order = opOrder($customer);
    opItem($order, $product, ['qty' => 1, 'line_total' => 100_000]);
    opItem($order, $product, ['qty' => 2, 'line_total' => 200_000]);

    app(CustomerPurchaseAggregateService::class)->rebuild();

    $row = productPurchaseRow($customer, $product);
    expect($row->orders_count)->toBe(1)
        ->and($row->items_count)->toBe(3)
        ->and($row->revenue)->toBe(300_000);
});

it('counts a product under every category it belongs to, not just one', function () {
    $customer = Customer::factory()->create();
    $product = Product::factory()->create();
    $shoes = ProductCategory::factory()->create();
    $sale = ProductCategory::factory()->create();
    $product->categories()->attach([$shoes->id, $sale->id]);
    opItem(opOrder($customer), $product, ['qty' => 1, 'line_total' => 150_000]);

    app(CustomerPurchaseAggregateService::class)->rebuild();

    $rows = DB::table('customer_category_purchases')->where('customer_id', $customer->id)->orderBy('category_id')->get();
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row->orders_count)->toBe(1)->and($row->items_count)->toBe(1)->and($row->revenue)->toBe(150_000);
    }
    expect($rows->pluck('category_id')->sort()->values()->all())->toBe(collect([$shoes->id, $sale->id])->sort()->values()->all());
});

it('sets last_bought_at to the most recent qualifying order', function () {
    $customer = Customer::factory()->create();
    $product = Product::factory()->create();
    $old = Order::factory()->for($customer)->create(['is_realized' => true, 'ordered_at' => now()->subDays(30)]);
    $recent = Order::factory()->for($customer)->create(['is_realized' => true, 'ordered_at' => now()->subDay()]);
    opItem($old, $product);
    opItem($recent, $product);

    app(CustomerPurchaseAggregateService::class)->rebuild();

    $row = productPurchaseRow($customer, $product);
    expect(Carbon::parse($row->last_bought_at)->isSameDay($recent->ordered_at))->toBeTrue();
});

it('is idempotent: running rebuild twice does not duplicate or change rows', function () {
    $customer = Customer::factory()->create();
    $product = Product::factory()->create();
    opItem(opOrder($customer), $product, ['line_total' => 250_000]);

    $service = app(CustomerPurchaseAggregateService::class);
    $service->rebuild();
    $first = productPurchaseRow($customer, $product);
    $service->rebuild();
    $second = productPurchaseRow($customer, $product);

    expect(DB::table('customer_product_purchases')->where('customer_id', $customer->id)->where('product_id', $product->id)->count())->toBe(1)
        ->and($second->revenue)->toBe($first->revenue)
        ->and($second->orders_count)->toBe($first->orders_count);
});

it('rebuilds from scratch: a stale row for a product no longer purchased is gone', function () {
    $customer = Customer::factory()->create();
    $product = Product::factory()->create();
    DB::table('customer_product_purchases')->insert([
        'customer_id' => $customer->id, 'product_id' => $product->id,
        'orders_count' => 99, 'items_count' => 99, 'revenue' => 99,
    ]);

    app(CustomerPurchaseAggregateService::class)->rebuild();

    expect(productPurchaseRow($customer, $product))->toBeNull();
});

it('reports row counts written to each table', function () {
    $customer = Customer::factory()->create();
    $product = Product::factory()->create();
    $product->categories()->attach(ProductCategory::factory()->create());
    opItem(opOrder($customer), $product);

    $summary = app(CustomerPurchaseAggregateService::class)->rebuild();

    expect($summary->productRows)->toBe(1)->and($summary->categoryRows)->toBe(1);
});
