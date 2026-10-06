<?php

declare(strict_types=1);

use App\Modules\Analytics\Jobs\BuildAffinityJob;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;

/* P6-05 (PRD §22): a thin Job wrapper around AffinityService::rebuild(). */

it('implements ShouldBeUnique so at most one rebuild runs at a time', function () {
    expect(in_array(ShouldBeUnique::class, class_implements(BuildAffinityJob::class), true))->toBeTrue();
});

it('sets uniqueFor greater than timeout, per PRD §22\'s rule for every whole-data job', function () {
    $job = new BuildAffinityJob;

    expect($job->uniqueFor)->toBeGreaterThan($job->timeout);
});

it('is queued on metrics, same as BuildCustomerPurchaseAggregatesJob', function () {
    expect((new BuildAffinityJob)->queue)->toBe('metrics');
});

it('rebuilds product_affinities when dispatched', function () {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    for ($i = 0; $i < 10; $i++) {
        $customerId = Customer::factory()->create()->id;
        DB::table('customer_product_purchases')->insert(['customer_id' => $customerId, 'product_id' => $a->id, 'orders_count' => 1, 'items_count' => 1, 'revenue' => 1]);
        DB::table('customer_product_purchases')->insert(['customer_id' => $customerId, 'product_id' => $b->id, 'orders_count' => 1, 'items_count' => 1, 'revenue' => 1]);
    }
    for ($i = 0; $i < 10; $i++) {
        DB::table('customer_product_purchases')->insert(['customer_id' => Customer::factory()->create()->id, 'product_id' => Product::factory()->create()->id, 'orders_count' => 1, 'items_count' => 1, 'revenue' => 1]);
    }

    BuildAffinityJob::dispatchSync();

    [$aId, $bId] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
    expect(DB::table('product_affinities')->where('level', 'product')->where('entity_a_id', $aId)->where('entity_b_id', $bId)->exists())->toBeTrue();
});
