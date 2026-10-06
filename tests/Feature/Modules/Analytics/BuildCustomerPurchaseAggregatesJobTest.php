<?php

declare(strict_types=1);

use App\Modules\Analytics\Jobs\BuildCustomerPurchaseAggregatesJob;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;

/* P6-01 (PRD §22): a thin Job wrapper around CustomerPurchaseAggregateService::rebuild(). */

it('implements ShouldBeUnique so at most one rebuild runs at a time', function () {
    expect(in_array(ShouldBeUnique::class, class_implements(BuildCustomerPurchaseAggregatesJob::class), true))->toBeTrue();
});

it('sets uniqueFor greater than timeout, per PRD §22\'s rule for every whole-data job', function () {
    $job = new BuildCustomerPurchaseAggregatesJob;

    expect($job->uniqueFor)->toBeGreaterThan($job->timeout);
});

it('rebuilds both aggregate tables when dispatched', function () {
    $customer = Customer::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->for($customer)->create(['is_realized' => true]);
    DB::table('order_items')->insert([
        'order_id' => $order->id, 'product_id' => $product->id, 'name_snapshot' => 'x',
        'qty' => 1, 'unit_price' => 100_000, 'line_subtotal' => 100_000, 'line_total' => 100_000,
    ]);

    BuildCustomerPurchaseAggregatesJob::dispatchSync();

    expect(DB::table('customer_product_purchases')->where('customer_id', $customer->id)->where('product_id', $product->id)->exists())->toBeTrue();
});
