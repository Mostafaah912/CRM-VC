<?php

declare(strict_types=1);

use App\Modules\Analytics\Enums\AffinityLevel;
use App\Modules\Analytics\Services\AffinityService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Models\ProductVariation;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;

/*
| P6-05 (TEST FIRST): PRD Sec.16's "one pattern for all levels" -- lift = confidence(A->B) / P(B), unordered
| pairs (b.id > a.id), HAVING co_customers >= the level's minimum, store only WHERE lift > 1.0. Category and
| product levels read the P6-01 aggregate tables (customer_category_purchases/customer_product_purchases);
| variation and basket levels read order_items directly (no such aggregate table exists for them) -- basket
| groups by order_id instead of customer_id (PRD C5: "customer level is primary; basket is just one more
| `level` value in the same table"). No OrderItem factory exists yet (same precedent as
| CustomerPurchaseAggregateServiceTest), so items are inserted directly via DB::table.
*/

function affOrder(Customer $customer): Order
{
    return Order::factory()->for($customer)->create(['is_realized' => true]);
}

function affItem(Order $order, ?Product $product, ?ProductVariation $variation = null): void
{
    DB::table('order_items')->insert([
        'order_id' => $order->id,
        'product_id' => $product?->id,
        'variation_id' => $variation?->id,
        'name_snapshot' => $product?->name ?? $variation?->sku ?? 'item',
        'qty' => 1,
        'unit_price' => 100_000,
        'line_subtotal' => 100_000,
        'line_total' => 100_000,
        'refunded_amount' => 0,
    ]);
}

function affinityPairRow(AffinityLevel $level, int $aId, int $bId): ?object
{
    return DB::table('product_affinities')
        ->where('level', $level->value)->where('entity_a_id', $aId)->where('entity_b_id', $bId)->first();
}

/**
 * Product level reads customer_product_purchases directly (P6-01's own aggregate table, already
 * filtered to realized/non-deleted/resolved purchases) -- AffinityService trusts it as already built,
 * exactly like production (BuildCustomerPurchaseAggregatesJob runs nightly; BuildAffinityJob runs
 * weekly, always after it). `$coBuyers` customers buy both A and B; `$noiseBuyers` buy only a third
 * product (population padding, no effect on A/B's own counts).
 */
function cpp(int $customerId, int $productId): void
{
    DB::table('customer_product_purchases')->insert([
        'customer_id' => $customerId, 'product_id' => $productId,
        'orders_count' => 1, 'items_count' => 1, 'revenue' => 100_000,
    ]);
}

function seedProductCoPurchase(Product $a, Product $b, int $coBuyers, int $noiseBuyers = 0): void
{
    for ($i = 0; $i < $coBuyers; $i++) {
        $customerId = Customer::factory()->create()->id;
        cpp($customerId, $a->id);
        cpp($customerId, $b->id);
    }

    if ($noiseBuyers > 0) {
        $noise = Product::factory()->create();
        for ($i = 0; $i < $noiseBuyers; $i++) {
            cpp(Customer::factory()->create()->id, $noise->id);
        }
    }
}

// ================================================================= product level

it('computes exact support/confidence/lift for a product pair at the minimum threshold', function () {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    seedProductCoPurchase($a, $b, coBuyers: 10, noiseBuyers: 10);

    app(AffinityService::class)->rebuild();

    [$aId, $bId] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
    $row = affinityPairRow(AffinityLevel::Product, $aId, $bId);
    expect($row)->not->toBeNull()
        ->and($row->co_customers)->toBe(10)
        ->and($row->a_customers)->toBe(10)
        ->and($row->b_customers)->toBe(10)
        ->and((float) $row->support)->toBe(0.5)
        ->and((float) $row->confidence)->toBe(1.0)
        ->and((float) $row->lift)->toBe(2.0);
});

it('does not store a product pair below the minimum co-purchase threshold (10)', function () {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    seedProductCoPurchase($a, $b, coBuyers: 9, noiseBuyers: 10);

    app(AffinityService::class)->rebuild();

    [$aId, $bId] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
    expect(affinityPairRow(AffinityLevel::Product, $aId, $bId))->toBeNull();
});

it('does not store a pair whose lift is exactly 1.0 (no noise population: B is universal)', function () {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    seedProductCoPurchase($a, $b, coBuyers: 10, noiseBuyers: 0);

    app(AffinityService::class)->rebuild();

    [$aId, $bId] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
    expect(affinityPairRow(AffinityLevel::Product, $aId, $bId))->toBeNull();
});

it('gives an empty result, not an error, for a product with no co-purchase at all', function () {
    $lonely = Product::factory()->create();
    cpp(Customer::factory()->create()->id, $lonely->id);

    $summary = app(AffinityService::class)->rebuild();

    expect($summary->productRows)->toBe(0)
        ->and(DB::table('product_affinities')->where('level', 'product')->count())->toBe(0);
});

it('is idempotent: rebuilding twice keeps the same single row with the same values', function () {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    seedProductCoPurchase($a, $b, coBuyers: 10, noiseBuyers: 10);
    $service = app(AffinityService::class);

    $service->rebuild();
    [$aId, $bId] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
    $first = affinityPairRow(AffinityLevel::Product, $aId, $bId);
    $service->rebuild();
    $second = affinityPairRow(AffinityLevel::Product, $aId, $bId);

    expect(DB::table('product_affinities')->where('level', 'product')->where('entity_a_id', $aId)->where('entity_b_id', $bId)->count())->toBe(1)
        ->and($second->lift)->toBe($first->lift)
        ->and($second->co_customers)->toBe($first->co_customers);
});

it('stores the pair as an unordered, smaller-id-first row (never both directions)', function () {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    seedProductCoPurchase($a, $b, coBuyers: 10, noiseBuyers: 10);

    app(AffinityService::class)->rebuild();

    [$aId, $bId] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
    expect(DB::table('product_affinities')->where('level', 'product')->where('entity_a_id', $bId)->where('entity_b_id', $aId)->exists())->toBeFalse()
        ->and(affinityPairRow(AffinityLevel::Product, $aId, $bId))->not->toBeNull();
});

// ================================================================= category level (min 20)

function ccp(int $customerId, int $categoryId): void
{
    DB::table('customer_category_purchases')->insert([
        'customer_id' => $customerId, 'category_id' => $categoryId,
        'orders_count' => 1, 'items_count' => 1, 'revenue' => 100_000,
    ]);
}

it('computes category-level affinity from customer_category_purchases (min co-purchase 20)', function () {
    $catA = ProductCategory::factory()->create();
    $catB = ProductCategory::factory()->create();
    $noiseCat = ProductCategory::factory()->create();

    for ($i = 0; $i < 20; $i++) {
        $customerId = Customer::factory()->create()->id;
        ccp($customerId, $catA->id);
        ccp($customerId, $catB->id);
    }
    for ($i = 0; $i < 20; $i++) {
        ccp(Customer::factory()->create()->id, $noiseCat->id);
    }

    app(AffinityService::class)->rebuild();

    [$aId, $bId] = $catA->id < $catB->id ? [$catA->id, $catB->id] : [$catB->id, $catA->id];
    $row = affinityPairRow(AffinityLevel::Category, $aId, $bId);
    expect($row)->not->toBeNull()
        ->and($row->co_customers)->toBe(20)
        ->and((float) $row->lift)->toBe(2.0);
});

it('does not store a category pair below its minimum threshold (20)', function () {
    $catA = ProductCategory::factory()->create();
    $catB = ProductCategory::factory()->create();

    for ($i = 0; $i < 19; $i++) {
        $customerId = Customer::factory()->create()->id;
        ccp($customerId, $catA->id);
        ccp($customerId, $catB->id);
    }

    app(AffinityService::class)->rebuild();

    [$aId, $bId] = $catA->id < $catB->id ? [$catA->id, $catB->id] : [$catB->id, $catA->id];
    expect(affinityPairRow(AffinityLevel::Category, $aId, $bId))->toBeNull();
});

// ================================================================= variation level (min 5)

it('computes variation-level affinity straight from order_items (min co-purchase 5)', function () {
    $varA = ProductVariation::factory()->create();
    $varB = ProductVariation::factory()->create();
    $noiseVar = ProductVariation::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        $order = affOrder(Customer::factory()->create());
        affItem($order, null, $varA);
        affItem($order, null, $varB);
    }
    for ($i = 0; $i < 5; $i++) {
        affItem(affOrder(Customer::factory()->create()), null, $noiseVar);
    }

    app(AffinityService::class)->rebuild();

    [$aId, $bId] = $varA->id < $varB->id ? [$varA->id, $varB->id] : [$varB->id, $varA->id];
    $row = affinityPairRow(AffinityLevel::Variation, $aId, $bId);
    expect($row)->not->toBeNull()
        ->and($row->co_customers)->toBe(5)
        ->and((float) $row->lift)->toBe(2.0);
});

it('does not store a variation pair below its minimum threshold (5)', function () {
    $varA = ProductVariation::factory()->create();
    $varB = ProductVariation::factory()->create();

    for ($i = 0; $i < 4; $i++) {
        $order = affOrder(Customer::factory()->create());
        affItem($order, null, $varA);
        affItem($order, null, $varB);
    }

    app(AffinityService::class)->rebuild();

    [$aId, $bId] = $varA->id < $varB->id ? [$varA->id, $varB->id] : [$varB->id, $varA->id];
    expect(affinityPairRow(AffinityLevel::Variation, $aId, $bId))->toBeNull();
});

// ================================================================= basket level (min 10, grouped by order, not customer)

it('computes basket-level affinity grouped by order_id, not customer_id (min co-purchase 10)', function () {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    $noise = Product::factory()->create();
    $sharedCustomer = Customer::factory()->create();

    for ($i = 0; $i < 10; $i++) {
        $order = affOrder($sharedCustomer);
        affItem($order, $a);
        affItem($order, $b);
    }
    for ($i = 0; $i < 10; $i++) {
        affItem(affOrder($sharedCustomer), $noise);
    }

    app(AffinityService::class)->rebuild();

    [$aId, $bId] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
    $row = affinityPairRow(AffinityLevel::Basket, $aId, $bId);
    expect($row)->not->toBeNull()
        ->and($row->co_customers)->toBe(10)
        ->and((float) $row->lift)->toBe(2.0);
});

it('does not store a basket pair below its minimum threshold (10)', function () {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    $customer = Customer::factory()->create();

    for ($i = 0; $i < 9; $i++) {
        $order = affOrder($customer);
        affItem($order, $a);
        affItem($order, $b);
    }

    app(AffinityService::class)->rebuild();

    [$aId, $bId] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
    expect(affinityPairRow(AffinityLevel::Basket, $aId, $bId))->toBeNull();
});

// ================================================================= rebuild summary + full rebuild-from-scratch

it('rebuilds every level from scratch: a stale row is gone even if nothing new qualifies', function () {
    DB::table('product_affinities')->insert([
        'level' => 'product', 'entity_a_id' => 1, 'entity_b_id' => 2,
        'co_customers' => 99, 'a_customers' => 99, 'b_customers' => 99,
        'support' => 1, 'confidence' => 1, 'lift' => 9,
    ]);

    $summary = app(AffinityService::class)->rebuild();

    expect(DB::table('product_affinities')->count())->toBe(0)
        ->and($summary->productRows)->toBe(0)->and($summary->categoryRows)->toBe(0)
        ->and($summary->variationRows)->toBe(0)->and($summary->basketRows)->toBe(0);
});

// ================================================================= top() (P6-06 dashboard read)

it('returns the strongest pairs for a level, ordered by lift descending, limited', function () {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    $c = Product::factory()->create();
    seedProductCoPurchase($a, $b, coBuyers: 10, noiseBuyers: 10); // lift 2.0
    seedProductCoPurchase($a, $c, coBuyers: 10, noiseBuyers: 100); // bigger population -> higher lift
    app(AffinityService::class)->rebuild();

    $top = app(AffinityService::class)->top(AffinityLevel::Product, limit: 1);

    expect($top)->toHaveCount(1)
        ->and($top[0]['level'])->toBe('product')
        ->and($top[0]['lift'])->toBeGreaterThan(2.0);
});

it('returns an empty list for a level with nothing stored', function () {
    expect(app(AffinityService::class)->top(AffinityLevel::Basket))->toBe([]);
});

it('names a product-level pair\'s two entities, via Catalog\'s own public service (P6-12)', function () {
    $a = Product::factory()->create(['name' => 'Alpha Tee']);
    $b = Product::factory()->create(['name' => 'Beta Tee']);
    seedProductCoPurchase($a, $b, coBuyers: 10, noiseBuyers: 10);
    app(AffinityService::class)->rebuild();

    $top = app(AffinityService::class)->top(AffinityLevel::Product, limit: 1);

    [$nameA, $nameB] = $a->id < $b->id ? ['Alpha Tee', 'Beta Tee'] : ['Beta Tee', 'Alpha Tee'];
    expect($top[0]['entity_a_name'])->toBe($nameA)
        ->and($top[0]['entity_b_name'])->toBe($nameB);
});

it('names a category-level pair\'s two entities too', function () {
    $catA = ProductCategory::factory()->create(['name' => 'Shirts']);
    $catB = ProductCategory::factory()->create(['name' => 'Pants']);
    $noiseCat = ProductCategory::factory()->create();

    for ($i = 0; $i < 20; $i++) {
        $customerId = Customer::factory()->create()->id;
        ccp($customerId, $catA->id);
        ccp($customerId, $catB->id);
    }
    for ($i = 0; $i < 20; $i++) {
        ccp(Customer::factory()->create()->id, $noiseCat->id);
    }
    app(AffinityService::class)->rebuild();

    $top = app(AffinityService::class)->top(AffinityLevel::Category, limit: 1);

    [$nameA, $nameB] = $catA->id < $catB->id ? ['Shirts', 'Pants'] : ['Pants', 'Shirts'];
    expect($top[0]['entity_a_name'])->toBe($nameA)
        ->and($top[0]['entity_b_name'])->toBe($nameB);
});

it('leaves entity names null for variation/basket levels: no simple name exists for either', function () {
    $va = ProductVariation::factory()->create();
    $vb = ProductVariation::factory()->create();
    $noiseVar = ProductVariation::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        $order = affOrder(Customer::factory()->create());
        affItem($order, null, $va);
        affItem($order, null, $vb);
    }
    for ($i = 0; $i < 5; $i++) {
        affItem(affOrder(Customer::factory()->create()), null, $noiseVar);
    }
    app(AffinityService::class)->rebuild();

    $top = app(AffinityService::class)->top(AffinityLevel::Variation, limit: 1);

    expect($top)->toHaveCount(1)
        ->and($top[0]['entity_a_name'])->toBeNull()
        ->and($top[0]['entity_b_name'])->toBeNull();
});

it('reports how many rows it wrote per level', function () {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    seedProductCoPurchase($a, $b, coBuyers: 10, noiseBuyers: 10);

    $summary = app(AffinityService::class)->rebuild();

    expect($summary->productRows)->toBe(1)
        ->and($summary->categoryRows)->toBe(0)
        ->and($summary->variationRows)->toBe(0)
        ->and($summary->basketRows)->toBe(0);
});

// ================================================================= topAll() (P6-08 Affinity page)

it('returns the top pairs for every level in one call, keyed by level', function () {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    seedProductCoPurchase($a, $b, coBuyers: 10, noiseBuyers: 10);
    app(AffinityService::class)->rebuild();

    $all = app(AffinityService::class)->topAll();

    expect($all)->toHaveKeys(['category', 'product', 'variation', 'basket'])
        ->and($all['product'])->toHaveCount(1)
        ->and($all['category'])->toBe([])
        ->and($all['variation'])->toBe([])
        ->and($all['basket'])->toBe([]);
});

it('limits each level independently in topAll()', function () {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    $c = Product::factory()->create();
    seedProductCoPurchase($a, $b, coBuyers: 10, noiseBuyers: 10);
    seedProductCoPurchase($a, $c, coBuyers: 10, noiseBuyers: 100);
    app(AffinityService::class)->rebuild();

    $all = app(AffinityService::class)->topAll(limitPerLevel: 1);

    expect($all['product'])->toHaveCount(1);
});
