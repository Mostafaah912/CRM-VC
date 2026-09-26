<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Models\ProductVariation;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Segments\Exceptions\RuleValidationException;
use App\Modules\Segments\Services\RuleCompiler;
use Illuminate\Support\Facades\DB;

/*
| PRD §17 RuleCompiler: rule -> Eloquent Builder against customers (JOIN customer_metrics),
| column names always resolved through the P5-01 whitelist, values always parameter bindings,
| behavior fields via whereExists/whereNotExists subqueries. Real PostgreSQL, real rows — this
| file proves correctness; Gate3SqlInjectionTest.php proves GATE 3.
|
| `RuleCompiler::compile($rule)->pluck('customers.id')->all()` is always inlined at the call site
| (not behind a local helper) — routing it through a same-file helper function defeats the Pest/
| Larastan PHPStan extension's return-type inference for `expect()`, turning `Expectation<list<int>>`
| into an untyped `Expectation<mixed|null>` that has no `->not` property.
*/

/**
 * @param  array<string, mixed>  $metricsOverrides
 * @param  array<string, mixed>  $customerOverrides
 */
function segmentCustomerWithMetrics(array $metricsOverrides = [], array $customerOverrides = []): Customer
{
    $customer = Customer::factory()->create($customerOverrides);

    DB::table('customer_metrics')->insert(array_merge([
        'customer_id' => $customer->id,
        'computed_at' => now(),
    ], $metricsOverrides));

    return $customer->fresh();
}

function realizedOrderWithItem(Customer $customer, ?int $productId = null, ?int $variationId = null): Order
{
    $order = Order::factory()->create(['customer_id' => $customer->id, 'is_realized' => true, 'status' => 'completed']);

    OrderItem::create([
        'order_id' => $order->id,
        'woo_item_id' => fake()->unique()->numberBetween(1, 9_000_000),
        'sku' => 'SKU-'.fake()->unique()->numberBetween(1, 9_000_000),
        'name_snapshot' => 'محصول آزمایشی',
        'qty' => 1, 'unit_price' => 100000, 'line_subtotal' => 100000, 'line_total' => 100000,
        'product_id' => $productId, 'variation_id' => $variationId,
    ]);

    return $order;
}

it('compiles a single leaf condition and filters customers', function () {
    $match = segmentCustomerWithMetrics(['total_orders' => 5]);
    $noMatch = segmentCustomerWithMetrics(['total_orders' => 1]);

    $ids = RuleCompiler::compile(['field' => 'total_orders', 'operator' => '>=', 'value' => 3])->pluck('customers.id')->all();

    expect($ids)->toContain($match->id);
    expect($ids)->not->toContain($noMatch->id);
});

it('compiles a nested AND/OR group with correct precedence', function () {
    $inAnd = segmentCustomerWithMetrics(['total_orders' => 5, 'rfm_segment' => 'champion']);
    $failsOr = segmentCustomerWithMetrics(['total_orders' => 5, 'rfm_segment' => 'lost']);
    $failsAnd = segmentCustomerWithMetrics(['total_orders' => 1, 'rfm_segment' => 'champion']);

    $rule = [
        'op' => 'AND',
        'children' => [
            ['field' => 'total_orders', 'operator' => '>=', 'value' => 2],
            ['op' => 'OR', 'children' => [
                ['field' => 'rfm_segment', 'operator' => '=', 'value' => 'champion'],
                ['field' => 'rfm_segment', 'operator' => '=', 'value' => 'loyal'],
            ]],
        ],
    ];

    $ids = RuleCompiler::compile($rule)->pluck('customers.id')->all();

    expect($ids)->toContain($inAnd->id);
    expect($ids)->not->toContain($failsOr->id);
    expect($ids)->not->toContain($failsAnd->id);
});

it('excludes soft-deleted customers', function () {
    $active = segmentCustomerWithMetrics(['total_orders' => 5]);
    $deleted = segmentCustomerWithMetrics(['total_orders' => 5]);
    $deleted->delete();

    $ids = RuleCompiler::compile(['field' => 'total_orders', 'operator' => '>=', 'value' => 1])->pluck('customers.id')->all();

    expect($ids)->toContain($active->id);
    expect($ids)->not->toContain($deleted->id);
});

it('applies every scalar comparison operator correctly', function (string $operator, int $value, int $matchOrders, int $noMatchOrders) {
    $match = segmentCustomerWithMetrics(['total_orders' => $matchOrders]);
    $noMatch = segmentCustomerWithMetrics(['total_orders' => $noMatchOrders]);

    $ids = RuleCompiler::compile(['field' => 'total_orders', 'operator' => $operator, 'value' => $value])->pluck('customers.id')->all();

    expect($ids)->toContain($match->id);
    expect($ids)->not->toContain($noMatch->id);
})->with([
    ['=', 5, 5, 6],
    ['!=', 5, 6, 5],
    ['>', 5, 6, 5],
    ['>=', 5, 5, 4],
    ['<', 5, 4, 5],
    ['<=', 5, 5, 6],
]);

it('applies "in" and "not_in"', function () {
    $champion = segmentCustomerWithMetrics(['rfm_segment' => 'champion']);
    $lost = segmentCustomerWithMetrics(['rfm_segment' => 'lost']);

    $inIds = RuleCompiler::compile(['field' => 'rfm_segment', 'operator' => 'in', 'value' => ['champion', 'loyal']])->pluck('customers.id')->all();
    $notInIds = RuleCompiler::compile(['field' => 'rfm_segment', 'operator' => 'not_in', 'value' => ['champion', 'loyal']])->pluck('customers.id')->all();

    expect($inIds)->toContain($champion->id);
    expect($inIds)->not->toContain($lost->id);
    expect($notInIds)->toContain($lost->id);
    expect($notInIds)->not->toContain($champion->id);
});

it('applies "between" inclusively', function () {
    $inside = segmentCustomerWithMetrics(['total_orders' => 3]);
    $boundary = segmentCustomerWithMetrics(['total_orders' => 5]);
    $outside = segmentCustomerWithMetrics(['total_orders' => 10]);

    $ids = RuleCompiler::compile(['field' => 'total_orders', 'operator' => 'between', 'value' => [2, 5]])->pluck('customers.id')->all();

    expect($ids)->toContain($inside->id);
    expect($ids)->toContain($boundary->id);
    expect($ids)->not->toContain($outside->id);
});

it('applies "is_null" and "is_not_null"', function () {
    $withNext = segmentCustomerWithMetrics(['expected_next_order_at' => now()->addDays(5)]);
    $withoutNext = segmentCustomerWithMetrics(['expected_next_order_at' => null]);

    $nullIds = RuleCompiler::compile(['field' => 'expected_next_order_at', 'operator' => 'is_null'])->pluck('customers.id')->all();
    $notNullIds = RuleCompiler::compile(['field' => 'expected_next_order_at', 'operator' => 'is_not_null'])->pluck('customers.id')->all();

    expect($nullIds)->toContain($withoutNext->id);
    expect($nullIds)->not->toContain($withNext->id);
    expect($notNullIds)->toContain($withNext->id);
    expect($notNullIds)->not->toContain($withoutNext->id);
});

it('applies "contains" as a substring match', function () {
    $match = segmentCustomerWithMetrics([], ['city' => 'تهران بزرگ']);
    $noMatch = segmentCustomerWithMetrics([], ['city' => 'شیراز']);

    $ids = RuleCompiler::compile(['field' => 'city', 'operator' => 'contains', 'value' => 'تهران'])->pluck('customers.id')->all();

    expect($ids)->toContain($match->id);
    expect($ids)->not->toContain($noMatch->id);
});

it('matches "bought_product" only for a realized order containing that product', function () {
    $product = Product::factory()->create();
    $buyer = segmentCustomerWithMetrics(['total_orders' => 1]);
    realizedOrderWithItem($buyer, productId: $product->id);
    $nonBuyer = segmentCustomerWithMetrics(['total_orders' => 0]);

    $ids = RuleCompiler::compile(['field' => 'product', 'operator' => 'bought_product', 'value' => $product->id])->pluck('customers.id')->all();

    expect($ids)->toContain($buyer->id);
    expect($ids)->not->toContain($nonBuyer->id);
});

it('ignores a non-realized order for "bought_product"', function () {
    $product = Product::factory()->create();
    $customer = segmentCustomerWithMetrics(['total_orders' => 0]);
    $order = Order::factory()->create(['customer_id' => $customer->id, 'is_realized' => false]);
    OrderItem::create([
        'order_id' => $order->id, 'woo_item_id' => 1, 'sku' => 'SKU-1', 'name_snapshot' => 'x',
        'qty' => 1, 'unit_price' => 1, 'line_subtotal' => 1, 'line_total' => 1, 'product_id' => $product->id,
    ]);

    $ids = RuleCompiler::compile(['field' => 'product', 'operator' => 'bought_product', 'value' => $product->id])->pluck('customers.id')->all();

    expect($ids)->not->toContain($customer->id);
});

it('applies "not_bought_product" as the inverse', function () {
    $product = Product::factory()->create();
    $buyer = segmentCustomerWithMetrics(['total_orders' => 1]);
    realizedOrderWithItem($buyer, productId: $product->id);
    $nonBuyer = segmentCustomerWithMetrics(['total_orders' => 0]);

    $ids = RuleCompiler::compile(['field' => 'product', 'operator' => 'not_bought_product', 'value' => $product->id])->pluck('customers.id')->all();

    expect($ids)->toContain($nonBuyer->id);
    expect($ids)->not->toContain($buyer->id);
});

it('matches "bought_category" via the product_category_product pivot', function () {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->create();
    $product->categories()->attach($category->id);

    $buyer = segmentCustomerWithMetrics(['total_orders' => 1]);
    realizedOrderWithItem($buyer, productId: $product->id);
    $nonBuyer = segmentCustomerWithMetrics(['total_orders' => 0]);

    $ids = RuleCompiler::compile(['field' => 'category', 'operator' => 'bought_category', 'value' => $category->id])->pluck('customers.id')->all();

    expect($ids)->toContain($buyer->id);
    expect($ids)->not->toContain($nonBuyer->id);
});

it('matches "bought_variation" only for the exact variation, not a sibling', function () {
    $product = Product::factory()->create();
    $variationA = ProductVariation::factory()->create(['product_id' => $product->id]);
    $variationB = ProductVariation::factory()->create(['product_id' => $product->id]);

    $buyerA = segmentCustomerWithMetrics(['total_orders' => 1]);
    realizedOrderWithItem($buyerA, productId: $product->id, variationId: $variationA->id);

    $ids = RuleCompiler::compile(['field' => 'variation', 'operator' => 'bought_variation', 'value' => $variationA->id])->pluck('customers.id')->all();
    $idsOther = RuleCompiler::compile(['field' => 'variation', 'operator' => 'bought_variation', 'value' => $variationB->id])->pluck('customers.id')->all();

    expect($ids)->toContain($buyerA->id);
    expect($idsOther)->not->toContain($buyerA->id);
});

it('matches "in_segment" and "not_in_segment" via segment_members', function () {
    $segmentId = DB::table('segments')->insertGetId([
        'name' => 'قهرمانان', 'type' => 'dynamic', 'rule' => json_encode(['field' => 'total_orders', 'operator' => '>=', 'value' => 1]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $member = segmentCustomerWithMetrics(['total_orders' => 1]);
    $nonMember = segmentCustomerWithMetrics(['total_orders' => 1]);
    DB::table('segment_members')->insert(['segment_id' => $segmentId, 'customer_id' => $member->id, 'added_at' => now()]);

    $inIds = RuleCompiler::compile(['field' => 'segment', 'operator' => 'in_segment', 'value' => [$segmentId]])->pluck('customers.id')->all();
    $notInIds = RuleCompiler::compile(['field' => 'segment', 'operator' => 'not_in_segment', 'value' => [$segmentId]])->pluck('customers.id')->all();

    expect($inIds)->toContain($member->id);
    expect($inIds)->not->toContain($nonMember->id);
    expect($notInIds)->toContain($nonMember->id);
    expect($notInIds)->not->toContain($member->id);
});

// ================================================================== P5-07b: within_days_of_now

it('matches expected_next_order_at inside a +-7-day window around a fixed "now", excludes outside it', function () {
    Carbon\Carbon::setTestNow('2026-06-15 12:00:00');

    $inside = segmentCustomerWithMetrics(['expected_next_order_at' => '2026-06-20 00:00:00']); // +5d
    $onLowerBoundary = segmentCustomerWithMetrics(['expected_next_order_at' => '2026-06-08 12:00:00']); // exactly -7d
    $onUpperBoundary = segmentCustomerWithMetrics(['expected_next_order_at' => '2026-06-22 12:00:00']); // exactly +7d
    $tooEarly = segmentCustomerWithMetrics(['expected_next_order_at' => '2026-06-01 00:00:00']); // -14d
    $tooLate = segmentCustomerWithMetrics(['expected_next_order_at' => '2026-07-01 00:00:00']); // +16d

    $ids = RuleCompiler::compile(['field' => 'expected_next_order_at', 'operator' => 'within_days_of_now', 'value' => 7])->pluck('customers.id')->all();

    expect($ids)->toContain($inside->id, $onLowerBoundary->id, $onUpperBoundary->id)
        ->and($ids)->not->toContain($tooEarly->id, $tooLate->id);

    Carbon\Carbon::setTestNow();
});

it('applies within_days_of_now to first_seen_at as well', function () {
    Carbon\Carbon::setTestNow('2026-06-15 12:00:00');

    $inside = segmentCustomerWithMetrics([], ['first_seen_at' => '2026-06-13 00:00:00']);
    $outside = segmentCustomerWithMetrics([], ['first_seen_at' => '2026-01-01 00:00:00']);

    $ids = RuleCompiler::compile(['field' => 'first_seen_at', 'operator' => 'within_days_of_now', 'value' => 3])->pluck('customers.id')->all();

    expect($ids)->toContain($inside->id)->not->toContain($outside->id);

    Carbon\Carbon::setTestNow();
});

it('recomputes within_days_of_now fresh on every compile — the same rule matches differently as "now" moves', function () {
    $customer = segmentCustomerWithMetrics(['expected_next_order_at' => '2026-06-20 00:00:00']);
    $rule = ['field' => 'expected_next_order_at', 'operator' => 'within_days_of_now', 'value' => 7];

    Carbon\Carbon::setTestNow('2026-06-15 00:00:00'); // 5 days before: inside the window
    $idsNear = RuleCompiler::compile($rule)->pluck('customers.id')->all();

    Carbon\Carbon::setTestNow('2026-08-01 00:00:00'); // over a month later: well outside
    $idsFar = RuleCompiler::compile($rule)->pluck('customers.id')->all();

    expect($idsNear)->toContain($customer->id)
        ->and($idsFar)->not->toContain($customer->id);

    Carbon\Carbon::setTestNow();
});

it('binds within_days_of_now as two date parameters, never concatenating the day count into SQL', function () {
    Carbon\Carbon::setTestNow('2026-06-15 12:00:00');

    $query = RuleCompiler::compile(['field' => 'expected_next_order_at', 'operator' => 'within_days_of_now', 'value' => 7]);

    expect($query->toSql())->toContain('between ? and ?')
        ->and($query->toSql())->not->toContain('7');

    $bindings = $query->getBindings();
    expect($bindings)->not->toContain(7)->not->toContain('7');

    $query->count();

    Carbon\Carbon::setTestNow();
});

it('propagates a RuleValidationException for a field outside the whitelist without building a query', function () {
    expect(fn () => RuleCompiler::compile(['field' => 'email', 'operator' => '=', 'value' => 'x']))
        ->toThrow(RuleValidationException::class);
});

it('propagates a RuleValidationException for a structurally invalid rule', function () {
    expect(fn () => RuleCompiler::compile(['op' => 'AND', 'children' => []]))
        ->toThrow(RuleValidationException::class);
});
