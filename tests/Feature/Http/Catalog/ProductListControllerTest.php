<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariation;
use App\Modules\Core\Models\Permission;
use App\Modules\Customers\Models\Customer;
use Illuminate\Support\Facades\DB;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P3-07 — GET /products: the product list with lifetime sales, behind auth + catalog.view. Sales are summed from realized orders
| only, a never-sold product shows zero (never an error, never null counts), the default sort is total_revenue DESC (top sellers
| first) then id DESC, and filters (name ILIKE, sku exact, status) combine with AND. Offset pagination, 25 a page.
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
});

function plGet($test, string $query = '', array $keys = ['catalog.view'])
{
    return $test->actingAs(Fx::userWith(...$keys))->get('/products'.$query);
}

function plProduct(array $overrides = []): Product
{
    return Product::factory()->create($overrides);
}

/** A product with one variation (the common "simple" case) and, optionally, realized sales for it. */
function plProductWithSku(string $sku, array $overrides = []): Product
{
    $product = plProduct($overrides);
    ProductVariation::factory()->create(['product_id' => $product->id, 'sku' => $sku]);

    return $product;
}

function plSale(Product $product, int $qty, int $lineTotal, bool $isRealized = true, string $status = 'completed', ?string $orderedAt = null): void
{
    $orderId = DB::table('orders')->insertGetId([
        'woo_order_id' => random_int(1, 999_999_999), 'customer_id' => Customer::factory()->create()->id,
        'status' => $status, 'is_realized' => $isRealized, 'total' => $lineTotal, 'ordered_at' => $orderedAt ?? now(),
    ]);
    DB::table('order_items')->insert([
        'order_id' => $orderId, 'product_id' => $product->id, 'sku' => 'x', 'name_snapshot' => $product->name,
        'qty' => $qty, 'unit_price' => intdiv($lineTotal, max($qty, 1)), 'line_subtotal' => $lineTotal, 'line_total' => $lineTotal,
    ]);
}

// ================================================================== access

it('redirects a guest to login', function () {
    $this->get('/products')->assertRedirect(route('login'));
});

it('forbids a signed-in user without catalog.view', function () {
    plProduct();

    $this->actingAs(Fx::userWith())->get('/products')->assertForbidden();
    $this->actingAs(Fx::userWith('orders.view', 'customers.view'))->get('/products')->assertForbidden();
});

it('lets an explicit deny beat the role grant', function () {
    $user = Fx::userWith('catalog.view');
    $permission = Permission::query()->where('module', 'catalog')->where('action', 'view')->firstOrFail();
    $user->permissionOverrides()->create(['permission_id' => $permission->id, 'effect' => 'deny']);

    $this->actingAs($user)->get('/products')->assertForbidden();
});

// ================================================================== the page

it('renders the products/index component with the documented props', function () {
    plProduct();

    plGet($this)->assertOk()->assertInertia(fn ($page) => $page
        ->component('products/index')
        ->has('products.data')
        ->has('filters')
        ->has('options.statuses'));
});

// ================================================================== the required test: product without a sale

it('shows a product with no sale as zero — never an error, never null', function () {
    $product = plProductWithSku('HM-NOSALE');

    $row = plGet($this)->inertiaProps()['products']['data'][0];

    expect($row['id'])->toBe($product->id)
        ->and($row['sku'])->toBe('HM-NOSALE')
        ->and($row['total_qty_sold'])->toBe(0)
        ->and($row['total_revenue'])->toBe(0)
        ->and($row['order_count'])->toBe(0)
        ->and($row['last_sold_at_jalali'])->toBeNull()
        ->and($row['last_sold_at_iso'])->toBeNull();
});

it('sums quantity, revenue and distinct order count, and shows the last realized sale', function () {
    // 2026-03-20 20:30 UTC = 1405/01/01 00:00 Tehran
    $product = plProductWithSku('HM-SOLD');
    plSale($product, 2, 200_000, orderedAt: '2026-03-01 10:00:00+00');
    plSale($product, 3, 300_000, orderedAt: '2026-03-20 20:30:00+00');

    $row = plGet($this)->inertiaProps()['products']['data'][0];

    expect($row['total_qty_sold'])->toBe(5)
        ->and($row['total_revenue'])->toBe(500_000)
        ->and($row['order_count'])->toBe(2)
        ->and($row['last_sold_at_jalali'])->toBe('1405/01/01 00:00:00')
        ->and($row['last_sold_at_iso'])->toBe('2026-03-20T20:30:00Z');
});

it('counts only realized orders, never a status string in code', function () {
    $product = plProductWithSku('HM-REALIZED-ONLY');
    plSale($product, 1, 100_000, isRealized: false, status: 'pending');
    plSale($product, 2, 200_000, isRealized: true, status: 'completed');

    $row = plGet($this)->inertiaProps()['products']['data'][0];

    expect($row['total_qty_sold'])->toBe(2)->and($row['total_revenue'])->toBe(200_000)->and($row['order_count'])->toBe(1);
});

it('counts one order once even when it holds two lines of the same product', function () {
    $product = plProductWithSku('HM-TWO-LINES');
    $orderId = DB::table('orders')->insertGetId(['woo_order_id' => 555, 'customer_id' => Customer::factory()->create()->id, 'is_realized' => true, 'status' => 'completed', 'total' => 300_000, 'ordered_at' => now()]);
    DB::table('order_items')->insert([
        ['order_id' => $orderId, 'product_id' => $product->id, 'name_snapshot' => 'x', 'qty' => 1, 'unit_price' => 100_000, 'line_subtotal' => 100_000, 'line_total' => 100_000],
        ['order_id' => $orderId, 'product_id' => $product->id, 'name_snapshot' => 'x', 'qty' => 2, 'unit_price' => 100_000, 'line_subtotal' => 200_000, 'line_total' => 200_000],
    ]);

    $row = plGet($this)->inertiaProps()['products']['data'][0];

    expect($row['total_qty_sold'])->toBe(3)->and($row['total_revenue'])->toBe(300_000)->and($row['order_count'])->toBe(1);
});

it('never sums an item whose product could not be resolved (product_id null)', function () {
    $product = plProductWithSku('HM-SOLO');
    $orderId = DB::table('orders')->insertGetId(['woo_order_id' => 777, 'customer_id' => Customer::factory()->create()->id, 'is_realized' => true, 'status' => 'completed', 'total' => 100_000, 'ordered_at' => now()]);
    DB::table('order_items')->insert(['order_id' => $orderId, 'product_id' => null, 'name_snapshot' => 'Ghost item', 'qty' => 5, 'unit_price' => 20_000, 'line_subtotal' => 100_000, 'line_total' => 100_000]);

    $row = plGet($this)->inertiaProps()['products']['data'][0];

    expect($row['total_qty_sold'])->toBe(0);
});

// ================================================================== the required test: name ILIKE

it('filters by name with ILIKE — case-insensitive substring, and escapes % and _', function () {
    plProduct(['name' => 'Cotton Shirt']);
    plProduct(['name' => 'Denim Jacket']);

    expect(array_column(plGet($this, '?name=cotton')->inertiaProps()['products']['data'], 'name'))->toBe(['Cotton Shirt'])
        ->and(array_column(plGet($this, '?name=SHIRT')->inertiaProps()['products']['data'], 'name'))->toBe(['Cotton Shirt']);
});

it('treats % and _ typed in the name filter as literal characters, not LIKE wildcards', function () {
    plProduct(['name' => '100% Cotton']);
    plProduct(['name' => 'XXX Cotton']);

    $rows = plGet($this, '?name='.urlencode('100% C'))->inertiaProps()['products']['data'];

    expect(array_column($rows, 'name'))->toBe(['100% Cotton']);
});

// ================================================================== the required test: sku exact

it('filters by sku exactly — a partial SKU matches nothing', function () {
    plProductWithSku('HM-01', ['name' => 'A']);
    plProductWithSku('HM-011', ['name' => 'B']);

    $rows = plGet($this, '?sku=HM-01')->inertiaProps()['products']['data'];

    expect(array_column($rows, 'name'))->toBe(['A']);
});

it('filters by sku across every product\'s variations, not only the representative one', function () {
    $product = plProduct(['name' => 'Variable product']);
    ProductVariation::factory()->create(['product_id' => $product->id, 'sku' => 'HM-A']);
    ProductVariation::factory()->create(['product_id' => $product->id, 'sku' => 'HM-B']);

    expect(array_column(plGet($this, '?sku=HM-B')->inertiaProps()['products']['data'], 'name'))->toBe(['Variable product']);
});

it('filters by status', function () {
    plProduct(['status' => 'publish', 'name' => 'Published']);
    plProduct(['status' => 'draft', 'name' => 'Drafted']);

    expect(array_column(plGet($this, '?status=draft')->inertiaProps()['products']['data'], 'name'))->toBe(['Drafted']);
});

it('rejects a status outside Catalog\'s ProductStatus enum', function () {
    plProduct();

    plGet($this, '?status=nonexistent')->assertRedirect()->assertSessionHasErrors(['status']);
});

it('combines filters with AND', function () {
    plProductWithSku('HM-AND', ['name' => 'Match', 'status' => 'publish']);
    plProductWithSku('HM-AND2', ['name' => 'Match', 'status' => 'draft']);

    $rows = plGet($this, '?name=Match&status=publish')->inertiaProps()['products']['data'];

    expect($rows)->toHaveCount(1)->and($rows[0]['sku'])->toBe('HM-AND');
});

// ================================================================== the required test: default order

it('sorts by total_revenue DESC by default — the best seller first, whatever order the products were created in', function () {
    $low = plProductWithSku('HM-LOW', ['name' => 'Low']);
    $high = plProductWithSku('HM-HIGH', ['name' => 'High']);
    $none = plProductWithSku('HM-NONE', ['name' => 'None']);
    plSale($low, 1, 100_000);
    plSale($high, 1, 900_000);

    $names = array_column(plGet($this)->inertiaProps()['products']['data'], 'name');

    expect($names)->toBe(['High', 'Low', 'None']);
});

it('breaks a tie in revenue by id, newest first', function () {
    $first = plProductWithSku('HM-T1', ['name' => 'Tie 1']);
    $second = plProductWithSku('HM-T2', ['name' => 'Tie 2']);
    plSale($first, 1, 500_000);
    plSale($second, 1, 500_000);

    expect(array_column(plGet($this)->inertiaProps()['products']['data'], 'name'))->toBe(['Tie 2', 'Tie 1']);
});

// ================================================================== the required test: pagination

it('paginates 25 at a time', function () {
    foreach (range(1, 30) as $i) {
        plProduct(['name' => "Product {$i}"]);
    }

    $first = plGet($this)->inertiaProps()['products'];
    $second = plGet($this, '?page=2')->inertiaProps()['products'];

    expect($first['data'])->toHaveCount(25)
        ->and($first['total'])->toBe(30)
        ->and($second['data'])->toHaveCount(5)
        ->and($second['current_page'])->toBe(2);
});

// ================================================================== what leaves

it('sends the WooCommerce admin link only when the store has a base URL configured', function () {
    $product = plProduct(['woo_product_id' => 4242]);

    config(['woo.base_url' => 'https://shop.example.test']);
    $withUrl = plGet($this)->inertiaProps()['products']['data'][0];

    config(['woo.base_url' => '']);
    $withoutUrl = plGet($this)->inertiaProps()['products']['data'][0];

    expect($withUrl['admin_url'])->toBe('https://shop.example.test/wp-admin/post.php?post=4242&action=edit')
        ->and($withoutUrl['admin_url'])->toBeNull();
});

it('sends exactly the documented row shape', function () {
    plProductWithSku('HM-SHAPE');

    $row = plGet($this)->inertiaProps()['products']['data'][0];

    expect(array_keys($row))->toEqualCanonicalizing([
        'id', 'woo_product_id', 'name', 'sku', 'status', 'admin_url',
        'total_qty_sold', 'total_revenue', 'order_count', 'last_sold_at_jalali', 'last_sold_at_iso',
    ]);
});

it('does not mutate anything: a page view writes no row', function () {
    plProduct();
    $before = [DB::table('products')->count(), DB::table('audit_logs')->count()];

    plGet($this);

    expect([DB::table('products')->count(), DB::table('audit_logs')->count()])->toBe($before);
});
