<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\ProductVariation;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Support\Facades\Artisan;

/*
| `hm:resolve-order-items` (P6 decision): thin wrapper around OrderItemBackfillService.
*/

it('prints the resolution summary', function () {
    $variation = ProductVariation::factory()->create(['sku' => 'SYN-SHIRT-S']);
    OrderItem::create([
        'order_id' => Order::factory()->create()->id,
        'woo_item_id' => 1,
        'sku' => 'SYN-SHIRT-S',
        'name_snapshot' => 'Test item',
        'qty' => 1,
        'unit_price' => 100,
        'line_subtotal' => 100,
        'line_total' => 100,
    ]);

    $code = Artisan::call('hm:resolve-order-items');
    $output = Artisan::output();

    expect($code)->toBe(0)->and($output)->toBe("resolved as variation: 1, resolved as product: 0, still unresolved: 0\n");
    expect(OrderItem::sole()->variation_id)->toBe($variation->id);
});
