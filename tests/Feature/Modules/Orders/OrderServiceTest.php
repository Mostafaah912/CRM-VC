<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariation;
use App\Modules\Customers\Enums\IdentitySource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerIdentity;
use App\Modules\Customers\Models\IdentityConflict;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderInput;
use App\Modules\Orders\Services\OrderItemInput;
use App\Modules\Orders\Services\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
| P2-06 — OrderService::upsert(): one Woo order + its complete item set, in ONE transaction (PRD §07/§10).
| Identity is woo_order_id; items are replaced (delete-then-insert); the customer comes from
| CustomerIdentityService (phone only, never email); is_realized comes from OrderStatusMapper; each item is
| resolved product -> variation in the PRD's four steps and is stored with NULL ids — never rejected — when
| nothing resolves. Refund figures belong to P2-07 and are never touched here. Real PostgreSQL; all data synthetic.
*/

$GLOBALS['order_logs'] = [];

beforeEach(function () {
    config(['logging.default' => 'null']);
    $GLOBALS['order_logs'] = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) {
        $GLOBALS['order_logs'][] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
    });
});

function orders(): OrderService
{
    return app(OrderService::class);
}

/** @param  array<string, mixed>  $overrides */
function orderItem(array $overrides = []): OrderItemInput
{
    return new OrderItemInput(...array_merge([
        'wooItemId' => 9001, 'wooProductId' => 101, 'wooVariationId' => null, 'sku' => 'SYN-TEE-001',
        'name' => 'تی‌شرت آزمایشی', 'quantity' => 1, 'unitPrice' => 403880, 'lineSubtotal' => 403880, 'lineTotal' => 403880,
    ], $overrides));
}

/** @param  array<string, mixed>  $overrides */
function orderInput(array $overrides = []): OrderInput
{
    $at = fn (string $t) => CarbonImmutable::parse($t, 'UTC');

    return new OrderInput(...array_merge([
        'wooOrderId' => 5001, 'number' => '5001', 'status' => 'completed', 'wooCustomerId' => 11,
        'billingFirstName' => 'مشتری', 'billingLastName' => 'نمونه', 'billingPhone' => '09000000101',
        'total' => 403880, 'discountTotal' => 0, 'shippingTotal' => 0, 'taxTotal' => 0,
        'couponCodes' => ['synth10'], 'paymentMethod' => 'synthetic_gateway',
        'orderedAt' => $at('2026-05-10 08:30:00'), 'paidAt' => $at('2026-05-10 08:35:00'),
        'completedAt' => $at('2026-05-12 09:00:00'), 'wooModifiedAt' => $at('2026-05-12 09:00:00'),
        'items' => [orderItem()],
    ], $overrides));
}

/** Catalog: product 101 (variations 1011 'SKU-A', 1012 'SKU-B') and product 202 (variation 2021 'SKU-C'). */
function seedCatalog(): array
{
    $p101 = Product::factory()->create(['woo_product_id' => 101, 'name' => 'محصول یک']);
    $p202 = Product::factory()->create(['woo_product_id' => 202, 'name' => 'محصول دو']);
    $v = fn (Product $p, int $woo, string $sku) => ProductVariation::factory()->create(['product_id' => $p->id, 'woo_variation_id' => $woo, 'sku' => $sku]);

    return ['p101' => $p101, 'p202' => $p202, 'v1011' => $v($p101, 1011, 'SKU-A'), 'v1012' => $v($p101, 1012, 'SKU-B'), 'v2021' => $v($p202, 2021, 'SKU-C')];
}

function itemSet(Order $order): array
{
    return $order->items()->orderBy('woo_item_id')->get()->map->only(['woo_item_id', 'product_id', 'variation_id', 'sku', 'name_snapshot', 'qty', 'unit_price', 'line_subtotal', 'line_total', 'refunded_qty', 'refunded_amount'])->all();
}

// ============================================================ order upsert

it('creates one order with every field, its customer and its item', function () {
    $id = orders()->upsert(orderInput());

    $order = Order::sole();
    expect($id)->toBe($order->id)
        ->and([$order->woo_order_id, $order->number, $order->status, $order->is_realized])->toBe([5001, '5001', 'completed', true])
        ->and([$order->total, $order->subtotal, $order->discount_total, $order->shipping_total, $order->tax_total])->toBe([403880, 403880, 0, 0, 0])
        ->and([$order->refunded_total, $order->is_fully_refunded, $order->net_revenue])->toBe([0, false, 403880])
        ->and($order->coupon_codes)->toBe(['synth10'])
        ->and($order->payment_method)->toBe('synthetic_gateway')
        ->and($order->ordered_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-05-10 08:30:00')
        ->and($order->paid_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-05-10 08:35:00')
        ->and($order->completed_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-05-12 09:00:00')
        ->and($order->woo_modified_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-05-12 09:00:00')
        ->and($order->customer_id)->toBe(Customer::sole()->id)
        ->and(OrderItem::count())->toBe(1);
});

it('is idempotent: syncing the same Woo order again changes nothing', function () {
    $id = orders()->upsert(orderInput());
    $orderState = Order::sole()->only(['id', 'woo_order_id', 'customer_id', 'status', 'is_realized', 'total', 'subtotal', 'coupon_codes', 'ordered_at', 'paid_at', 'completed_at']);
    $items = itemSet(Order::sole());

    $again = orders()->upsert(orderInput());
    orders()->upsert(orderInput());

    expect($again)->toBe($id)
        ->and(Order::count())->toBe(1)
        ->and(Order::sole()->only(['id', 'woo_order_id', 'customer_id', 'status', 'is_realized', 'total', 'subtotal', 'coupon_codes', 'ordered_at', 'paid_at', 'completed_at']))->toEqual($orderState)
        ->and(itemSet(Order::sole()))->toEqual($items)
        ->and(OrderItem::count())->toBe(1)
        ->and(Customer::count())->toBe(1)
        ->and(CustomerIdentity::count())->toBe(1);
});

it('updates the existing order when Woo changed it: status, totals, dates, payment method', function () {
    $id = orders()->upsert(orderInput(['status' => 'processing', 'paidAt' => null, 'completedAt' => null, 'paymentMethod' => null]));
    expect(Order::sole()->is_realized)->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00:00', 'UTC'));
    orders()->upsert(orderInput([
        'status' => 'cancelled', 'total' => 500000, 'discountTotal' => 10000, 'shippingTotal' => 25000, 'taxTotal' => 5000,
        'paymentMethod' => 'cod', 'couponCodes' => [],
        'wooModifiedAt' => CarbonImmutable::parse('2026-05-20 12:00:00', 'UTC'),
    ]));

    $order = Order::sole();
    expect($order->id)->toBe($id)
        ->and([$order->status, $order->is_realized])->toBe(['cancelled', false])
        ->and([$order->total, $order->discount_total, $order->shipping_total, $order->tax_total])->toBe([500000, 10000, 25000, 5000])
        ->and($order->payment_method)->toBe('cod')
        ->and($order->coupon_codes)->toBe([])
        ->and($order->paid_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-05-10 08:35:00')
        ->and($order->completed_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-05-12 09:00:00')
        ->and($order->woo_modified_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-05-20 12:00:00')
        ->and($order->synced_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-06-01 10:00:00');
});

it('follows payment and completion timestamps as Woo reports them, including clearing them', function () {
    orders()->upsert(orderInput(['paidAt' => null, 'completedAt' => null]));
    expect(Order::sole()->only(['paid_at', 'completed_at']))->toBe(['paid_at' => null, 'completed_at' => null]);

    orders()->upsert(orderInput());
    expect(Order::sole()->paid_at)->not->toBeNull()->and(Order::sole()->completed_at)->not->toBeNull();

    orders()->upsert(orderInput(['completedAt' => null]));
    expect(Order::sole()->completed_at)->toBeNull();
});

it('keeps money as exact integers, including very large amounts', function () {
    orders()->upsert(orderInput(['total' => 11340000000, 'discountTotal' => 1, 'shippingTotal' => 999999999, 'taxTotal' => 7, 'items' => [
        orderItem(['unitPrice' => 3780000000, 'lineSubtotal' => 11340000000, 'lineTotal' => 11340000000, 'quantity' => 3]),
    ]]));

    $order = Order::sole();
    $item = OrderItem::sole();
    expect([$order->total, $order->discount_total, $order->shipping_total, $order->tax_total, $order->subtotal])->toBe([11340000000, 1, 999999999, 7, 11340000000])
        ->and([$item->qty, $item->unit_price, $item->line_subtotal, $item->line_total])->toBe([3, 3780000000, 11340000000, 11340000000])
        ->and(array_filter([$order->total, $order->subtotal, $item->unit_price], fn ($m) => ! is_int($m)))->toBe([]);
});

it('derives the order subtotal from its items\' line subtotals (Woo sends none on the order)', function () {
    orders()->upsert(orderInput(['total' => 1250000, 'discountTotal' => 50000, 'items' => [
        orderItem(['wooItemId' => 1, 'lineSubtotal' => 1300000, 'lineTotal' => 1250000]),
        orderItem(['wooItemId' => 2, 'lineSubtotal' => 200, 'lineTotal' => 200]),
    ]]));

    expect(Order::sole()->subtotal)->toBe(1300200);
});

it('updates a soft-deleted order in place instead of colliding with its unique key, and does not restore it', function () {
    $id = orders()->upsert(orderInput());
    Order::sole()->delete();

    orders()->upsert(orderInput(['status' => 'processing']));

    $order = Order::withTrashed()->sole();
    expect($order->id)->toBe($id)->and($order->trashed())->toBeTrue()->and($order->status)->toBe('processing');
});

// ================================================================== status

it('takes is_realized from OrderStatusMapper, so the configured list decides and nothing is hardcoded', function () {
    orders()->upsert(orderInput(['wooOrderId' => 1, 'status' => 'completed']));
    orders()->upsert(orderInput(['wooOrderId' => 2, 'status' => 'pending', 'items' => [orderItem(['wooItemId' => 2])]]));
    orders()->upsert(orderInput(['wooOrderId' => 3, 'status' => 'refunded', 'items' => [orderItem(['wooItemId' => 3])]]));
    orders()->upsert(orderInput(['wooOrderId' => 4, 'status' => 'wc-completed', 'items' => [orderItem(['wooItemId' => 4])]]));
    expect(Order::orderBy('woo_order_id')->pluck('is_realized')->all())->toBe([true, false, false, false]);

    config(['woo.realized_statuses' => ['refunded']]);
    orders()->upsert(orderInput(['wooOrderId' => 1, 'status' => 'completed']));
    orders()->upsert(orderInput(['wooOrderId' => 3, 'status' => 'refunded', 'items' => [orderItem(['wooItemId' => 3])]]));
    expect(Order::orderBy('woo_order_id')->pluck('is_realized')->all())->toBe([false, false, true, false]);
});

it('stores the raw Woo status exactly, even a custom one', function () {
    orders()->upsert(orderInput(['status' => 'a-custom-status']));

    expect(Order::sole()->status)->toBe('a-custom-status')->and(Order::sole()->is_realized)->toBeFalse();
});

// ============================================================ refund fields

it('never touches the refund figures P2-07 owns: a resync keeps refunded_total, is_fully_refunded and item refunds', function () {
    orders()->upsert(orderInput());
    Order::query()->update(['refunded_total' => 100000, 'is_fully_refunded' => true]);
    OrderItem::query()->update(['refunded_qty' => 1, 'refunded_amount' => 100000]);

    orders()->upsert(orderInput(['status' => 'processing', 'wooModifiedAt' => CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC')]));

    $order = Order::sole();
    $item = OrderItem::sole();
    expect([$order->refunded_total, $order->is_fully_refunded, $order->net_revenue])->toBe([100000, true, 303880])
        ->and([$item->refunded_qty, $item->refunded_amount])->toBe([1, 100000]);
});

it('does not carry refund figures onto an item that is new to the order', function () {
    orders()->upsert(orderInput());
    OrderItem::query()->update(['refunded_qty' => 1, 'refunded_amount' => 100000]);

    orders()->upsert(orderInput(['items' => [orderItem(['wooItemId' => 9001]), orderItem(['wooItemId' => 9002, 'sku' => null, 'wooProductId' => null])]]));

    expect(OrderItem::where('woo_item_id', 9001)->sole()->refunded_amount)->toBe(100000)
        ->and(OrderItem::where('woo_item_id', 9002)->sole()->only(['refunded_qty', 'refunded_amount']))->toBe(['refunded_qty' => 0, 'refunded_amount' => 0]);
});

// ================================================================ customer

it('resolves the customer through CustomerIdentityService, by normalized phone', function (string $phone) {
    orders()->upsert(orderInput(['wooOrderId' => 1, 'billingPhone' => '09000000101']));
    orders()->upsert(orderInput(['wooOrderId' => 2, 'billingPhone' => $phone, 'items' => [orderItem(['wooItemId' => 2])]]));

    expect(Customer::count())->toBe(1)
        ->and(Order::pluck('customer_id')->unique()->all())->toBe([Customer::sole()->id])
        ->and(Customer::sole()->phone_normalized)->toBe('989000000101');
})->with(['+989000000101', '0900 000 0101', '۰۹۰۰۰۰۰۰۱۰۱', "\u{200F}09000000101"]);

it('records the Woo identity: a registered user by Woo customer id, a guest by order id', function () {
    orders()->upsert(orderInput(['wooOrderId' => 1, 'wooCustomerId' => 11, 'billingPhone' => '09000000101']));
    orders()->upsert(orderInput(['wooOrderId' => 2, 'wooCustomerId' => null, 'billingPhone' => '09000000102', 'items' => [orderItem(['wooItemId' => 2])]]));

    expect(CustomerIdentity::where('source', IdentitySource::WooUser)->sole()->source_id)->toBe('11')
        ->and(CustomerIdentity::where('source', IdentitySource::WooGuestOrder)->sole()->source_id)->toBe('2');
});

it('never matches a customer on email: an order has no email input, and a stranger\'s email matters to nobody', function () {
    Customer::factory()->create(['phone_normalized' => '989000000199', 'email' => 'someone@example.test']);
    $parameters = array_map(fn (ReflectionParameter $p) => $p->getName(), (new ReflectionClass(OrderInput::class))->getConstructor()?->getParameters() ?? []);

    orders()->upsert(orderInput(['billingPhone' => '09000000102']));

    expect($parameters)->not->toContain('email')
        ->and(Customer::count())->toBe(2)
        ->and(Order::sole()->customer_id)->toBe(Customer::where('phone_normalized', '989000000102')->sole()->id);
});

it('keeps the order and the one customer when the last name conflicts, leaving a pending conflict for review', function () {
    orders()->upsert(orderInput(['wooOrderId' => 1, 'billingLastName' => 'نمونه']));

    orders()->upsert(orderInput(['wooOrderId' => 2, 'billingLastName' => 'آزمایشی', 'items' => [orderItem(['wooItemId' => 2])]]));

    $conflict = IdentityConflict::sole();
    expect(Customer::count())->toBe(1)
        ->and(Order::count())->toBe(2)
        ->and(Order::pluck('customer_id')->unique()->all())->toBe([Customer::sole()->id])
        ->and($conflict->woo_order_id)->toBe(2)
        ->and(Customer::sole()->last_name)->toBe('نمونه');
});

it('moves an order to the new customer when Woo now says a different phone', function () {
    orders()->upsert(orderInput(['billingPhone' => '09000000101']));
    $first = Customer::sole()->id;

    orders()->upsert(orderInput(['billingPhone' => '09000000102']));

    expect(Order::sole()->customer_id)->not->toBe($first)
        ->and(Customer::count())->toBe(2);
});

it('stores an order with no usable phone flagged for review — no customer, one no_phone conflict, and no phone leaked', function (?string $phone) {
    orders()->upsert(orderInput(['billingPhone' => $phone]));

    $order = Order::sole();
    expect($order->only(['woo_order_id', 'customer_id', 'needs_phone_review']))->toBe(['woo_order_id' => 5001, 'customer_id' => null, 'needs_phone_review' => true])
        ->and(Customer::withTrashed()->count())->toBe(0)
        ->and(IdentityConflict::sole()->only(['woo_order_id', 'reason', 'customer_id']))->toBe(['woo_order_id' => 5001, 'reason' => 'no_phone', 'customer_id' => null])
        ->and(json_encode(IdentityConflict::sole()->toArray()))->not->toContain('12345')->not->toContain('0812');
})->with([[null], [''], ['12345'], ['08123456789']]);

// ================================================================== items

it('persists every item field exactly, snapshotting sku and name', function () {
    orders()->upsert(orderInput(['items' => [orderItem(['wooItemId' => 9001, 'sku' => 'SYN-TEE-001', 'name' => 'تی‌شرت آزمایشی', 'quantity' => 2, 'unitPrice' => 200000, 'lineSubtotal' => 400000, 'lineTotal' => 380000])]]));

    $item = OrderItem::sole();
    expect($item->order_id)->toBe(Order::sole()->id)
        ->and([$item->woo_item_id, $item->sku, $item->name_snapshot, $item->qty, $item->unit_price, $item->line_subtotal, $item->line_total, $item->refunded_qty, $item->refunded_amount])
        ->toBe([9001, 'SYN-TEE-001', 'تی‌شرت آزمایشی', 2, 200000, 400000, 380000, 0, 0]);
});

it('replaces the item set: dropped items go, changed items change, new items arrive, none duplicate', function () {
    orders()->upsert(orderInput(['items' => [
        orderItem(['wooItemId' => 1, 'quantity' => 1]),
        orderItem(['wooItemId' => 2, 'sku' => 'GONE']),
    ]]));

    orders()->upsert(orderInput(['items' => [
        orderItem(['wooItemId' => 1, 'quantity' => 4, 'lineTotal' => 1]),
        orderItem(['wooItemId' => 3, 'sku' => 'NEW']),
    ]]));

    expect(OrderItem::orderBy('woo_item_id')->pluck('woo_item_id')->all())->toBe([1, 3])
        ->and(OrderItem::where('woo_item_id', 1)->sole()->only(['qty', 'line_total']))->toBe(['qty' => 4, 'line_total' => 1]);
});

it('clears all items when Woo reports none', function () {
    orders()->upsert(orderInput());

    orders()->upsert(orderInput(['items' => []]));

    expect(OrderItem::count())->toBe(0)->and(Order::sole()->subtotal)->toBe(0);
});

it('keeps items of other orders untouched', function () {
    orders()->upsert(orderInput(['wooOrderId' => 1]));
    orders()->upsert(orderInput(['wooOrderId' => 2, 'items' => [orderItem(['wooItemId' => 50])]]));

    orders()->upsert(orderInput(['wooOrderId' => 1, 'items' => [orderItem(['wooItemId' => 9001, 'quantity' => 9])]]));

    expect(OrderItem::where('order_id', Order::where('woo_order_id', 2)->sole()->id)->pluck('woo_item_id')->all())->toBe([50]);
});

it('never rejects an order over an overlong name or sku: the snapshot is cut to its column, resolution uses the full sku', function () {
    $catalog = seedCatalog();
    $longSku = 'SKU-A';
    orders()->upsert(orderInput(['items' => [orderItem(['name' => str_repeat('ا', 400), 'sku' => str_repeat('س', 200), 'wooProductId' => null]), orderItem(['wooItemId' => 2, 'sku' => $longSku, 'wooProductId' => null])]]));

    $long = OrderItem::where('woo_item_id', 9001)->sole();
    expect(mb_strlen($long->name_snapshot))->toBe(250)->and(mb_strlen((string) $long->sku))->toBe(80)
        ->and(OrderItem::where('woo_item_id', 2)->sole()->variation_id)->toBe($catalog['v1011']->id);
});

// ============================================================ transaction

it('rolls the whole order back, restoring the previous state, when the new item set cannot be written', function () {
    orders()->upsert(orderInput(['billingPhone' => '09000000101', 'items' => [orderItem(['wooItemId' => 1]), orderItem(['wooItemId' => 2])]]));
    $before = ['order' => Order::sole()->only(['id', 'status', 'total', 'customer_id']), 'items' => itemSet(Order::sole()), 'customers' => Customer::count()];

    expect(fn () => orders()->upsert(orderInput([
        'status' => 'cancelled', 'total' => 1, 'billingPhone' => '09000000199',
        'items' => [orderItem(['wooItemId' => 7]), orderItem(['wooItemId' => 7])],
    ])))->toThrow(QueryException::class);

    expect(Order::sole()->only(['id', 'status', 'total', 'customer_id']))->toBe($before['order'])
        ->and(itemSet(Order::sole()))->toEqual($before['items'])
        ->and(Customer::count())->toBe($before['customers']);
});

it('leaves nothing behind when a NEW order fails halfway', function () {
    expect(fn () => orders()->upsert(orderInput(['items' => [orderItem(['wooItemId' => 7]), orderItem(['wooItemId' => 7])]])))
        ->toThrow(QueryException::class);

    expect(Order::withTrashed()->count())->toBe(0)->and(OrderItem::count())->toBe(0)->and(Customer::withTrashed()->count())->toBe(0);
});

// ======================================================== product resolution

it('STEP 1: resolves by Woo variation id, setting both the variation and its product', function () {
    $c = seedCatalog();

    orders()->upsert(orderInput(['items' => [orderItem(['wooVariationId' => 1012, 'wooProductId' => 101, 'sku' => 'IGNORED-SKU'])]]));

    $item = OrderItem::sole();
    expect([$item->variation_id, $item->product_id])->toBe([$c['v1012']->id, $c['p101']->id])
        ->and($item->sku)->toBe('IGNORED-SKU');
});

it('STEP 1 wins over the later steps: the variation id beats a SKU that belongs to another variation', function () {
    $c = seedCatalog();

    orders()->upsert(orderInput(['items' => [orderItem(['wooVariationId' => 1011, 'wooProductId' => 101, 'sku' => 'SKU-B'])]]));

    expect(OrderItem::sole()->variation_id)->toBe($c['v1011']->id);
});

it('STEP 2: a variation id Woo sent that we do not know falls through to product id + SKU', function () {
    $c = seedCatalog();

    orders()->upsert(orderInput(['items' => [orderItem(['wooVariationId' => 999999, 'wooProductId' => 101, 'sku' => 'SKU-B'])]]));

    $item = OrderItem::sole();
    expect([$item->variation_id, $item->product_id])->toBe([$c['v1012']->id, $c['p101']->id]);
});

it('STEP 2: with no variation id at all, product id + SKU resolves', function () {
    $c = seedCatalog();

    orders()->upsert(orderInput(['items' => [orderItem(['wooVariationId' => null, 'wooProductId' => 101, 'sku' => 'SKU-A'])]]));

    expect([OrderItem::sole()->variation_id, OrderItem::sole()->product_id])->toBe([$c['v1011']->id, $c['p101']->id]);
});

it('STEP 3: when product id + SKU does not match, the SKU alone resolves', function () {
    $c = seedCatalog();

    // SKU-A belongs to product 101, but the line claims product 202 -> step 2 misses -> step 3 finds the SKU
    orders()->upsert(orderInput(['items' => [orderItem(['wooVariationId' => null, 'wooProductId' => 202, 'sku' => 'SKU-A'])]]));

    $item = OrderItem::sole();
    expect([$item->variation_id, $item->product_id])->toBe([$c['v1011']->id, $c['p101']->id]);
});

it('STEP 3: with no product id, the SKU alone resolves', function () {
    $c = seedCatalog();

    orders()->upsert(orderInput(['items' => [orderItem(['wooVariationId' => null, 'wooProductId' => null, 'sku' => 'SKU-C'])]]));

    expect([OrderItem::sole()->variation_id, OrderItem::sole()->product_id])->toBe([$c['v2021']->id, $c['p202']->id]);
});

it('STEP 3.5 (P6 decision, not PRD\'s original 4 steps): a simple product\'s own SKU resolves, with variation_id NULL', function () {
    $c = seedCatalog();
    $c['p101']->update(['sku' => 'SIMPLE-SKU']);

    orders()->upsert(orderInput(['items' => [orderItem(['wooVariationId' => null, 'wooProductId' => null, 'sku' => 'SIMPLE-SKU'])]]));

    $item = OrderItem::sole();
    expect([$item->product_id, $item->variation_id])->toBe([$c['p101']->id, null]);
});

it('STEP 3.5 only runs once steps 1-3 all miss: a variation SKU still wins over a product SKU', function () {
    $c = seedCatalog();
    $c['p202']->update(['sku' => 'SKU-A']); // same value as v1011's SKU, on a DIFFERENT product

    orders()->upsert(orderInput(['items' => [orderItem(['wooVariationId' => null, 'wooProductId' => null, 'sku' => 'SKU-A'])]]));

    // Step 3 (variation SKU) already resolves 'SKU-A' to v1011/p101; step 3.5 never runs.
    expect([OrderItem::sole()->product_id, OrderItem::sole()->variation_id])->toBe([$c['p101']->id, $c['v1011']->id]);
});

it('STEP 4: a product SKU that matches nothing still ends up unresolved', function () {
    seedCatalog();

    orders()->upsert(orderInput(['items' => [orderItem(['wooVariationId' => null, 'wooProductId' => null, 'sku' => 'NO-PRODUCT-SKU'])]]));

    expect([OrderItem::sole()->product_id, OrderItem::sole()->variation_id])->toBe([null, null]);
});

it('STEP 4: nothing resolves -> NULL ids, sku and name snapshot kept, the order still succeeds, and it is logged', function () {
    seedCatalog();
    $productsBefore = Product::count();

    orders()->upsert(orderInput(['total' => 777, 'items' => [orderItem(['wooItemId' => 9001, 'wooVariationId' => 999999, 'wooProductId' => 999999, 'sku' => 'NO-SUCH-SKU', 'name' => 'محصول ناشناخته', 'lineTotal' => 777])]]));

    $item = OrderItem::sole();
    $warnings = array_values(array_filter($GLOBALS['order_logs'], fn (array $l) => $l['level'] === 'warning'));
    expect([$item->product_id, $item->variation_id])->toBe([null, null])
        ->and([$item->sku, $item->name_snapshot, $item->line_total])->toBe(['NO-SUCH-SKU', 'محصول ناشناخته', 777])
        ->and(Order::sole()->total)->toBe(777)
        ->and(Product::count())->toBe($productsBefore)
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0]['context'])->toMatchArray(['woo_order_id' => 5001, 'woo_item_id' => 9001]);
});

it('STEP 4: a line with no ids and no SKU at all is stored unresolved, not rejected', function () {
    seedCatalog();

    orders()->upsert(orderInput(['items' => [orderItem(['wooVariationId' => null, 'wooProductId' => null, 'sku' => null, 'name' => 'قلم بدون شناسه'])]]));

    $item = OrderItem::sole();
    expect([$item->product_id, $item->variation_id, $item->sku, $item->name_snapshot])->toBe([null, null, null, 'قلم بدون شناسه']);
});

it('never resolves by product name or by anything fuzzy, and never creates catalog rows', function () {
    $c = seedCatalog();
    $variationsBefore = ProductVariation::count();

    orders()->upsert(orderInput(['items' => [
        orderItem(['wooItemId' => 1, 'wooVariationId' => null, 'wooProductId' => null, 'sku' => null, 'name' => $c['p101']->name]),
        orderItem(['wooItemId' => 2, 'wooVariationId' => null, 'wooProductId' => null, 'sku' => 'sku-a', 'name' => 'حروف کوچک']),
        orderItem(['wooItemId' => 3, 'wooVariationId' => null, 'wooProductId' => 101, 'sku' => 'SKU-', 'name' => 'پیشوند']),
    ]]));

    expect(OrderItem::whereNotNull('product_id')->count())->toBe(0)
        ->and(Product::count())->toBe(2)
        ->and(ProductVariation::count())->toBe($variationsBefore);
});

it('never treats a missing variation id (Woo 0) as a real variation identity', function () {
    $c = seedCatalog();
    ProductVariation::factory()->create(['product_id' => $c['p202']->id, 'woo_variation_id' => 0, 'sku' => 'ZERO-TRAP']);

    orders()->upsert(orderInput(['items' => [orderItem(['wooVariationId' => null, 'wooProductId' => null, 'sku' => null])]]));

    expect(OrderItem::sole()->variation_id)->toBeNull();
});

it('is unambiguous by construction: the database allows one variation per SKU, so no tie-break exists to invent', function () {
    $c = seedCatalog();

    expect(fn () => DB::transaction(fn () => ProductVariation::factory()->create(['product_id' => $c['p202']->id, 'woo_variation_id' => 5555, 'sku' => 'SKU-A'])))
        ->toThrow(QueryException::class);
});

it('re-resolves on every sync: an item unresolved today links once the catalog has caught up', function () {
    orders()->upsert(orderInput(['items' => [orderItem(['wooVariationId' => 1011, 'wooProductId' => 101, 'sku' => 'SKU-A'])]]));
    expect(OrderItem::sole()->variation_id)->toBeNull();

    $c = seedCatalog();
    orders()->upsert(orderInput(['items' => [orderItem(['wooVariationId' => 1011, 'wooProductId' => 101, 'sku' => 'SKU-A'])]]));

    expect(OrderItem::sole()->variation_id)->toBe($c['v1011']->id);
});

// ============================================ the ORDER of the four steps

/**
 * The catalog lookups an upsert issued, as 1 / 2 / 3 for steps 1, 2 and 3 — the only way to see the priority,
 * because a SKU is unique and steps 2 and 3 therefore return the same variation whenever both match.
 *
 * @return list<int>
 */
function lookupSteps(callable $run): array
{
    $steps = [];
    DB::listen(function ($query) use (&$steps) {
        $sql = strtolower($query->sql);

        if (! str_starts_with($sql, 'select') || ! str_contains($sql, '"product_variations"')) {
            return;
        }

        $steps[] = match (true) {
            str_contains($sql, '"woo_variation_id" = ') => 1,
            str_contains($sql, 'exists (select * from "products"') => 2,
            str_contains($sql, '"sku" = ') => 3,
            default => 0,
        };
    });
    $run();

    return $steps;
}

it('tries the steps strictly in order and stops at the first hit', function (array $line, array $expectedSteps) {
    seedCatalog();

    $steps = lookupSteps(fn () => orders()->upsert(orderInput(['items' => [orderItem($line)]])));

    expect($steps)->toBe($expectedSteps);
})->with([
    'variation id hits -> step 1 only' => [['wooVariationId' => 1011, 'wooProductId' => 101, 'sku' => 'SKU-A'], [1]],
    'variation id misses, product+sku hits -> 1, 2' => [['wooVariationId' => 999999, 'wooProductId' => 101, 'sku' => 'SKU-A'], [1, 2]],
    'variation id misses, product+sku misses, sku hits -> 1, 2, 3' => [['wooVariationId' => 999999, 'wooProductId' => 202, 'sku' => 'SKU-A'], [1, 2, 3]],
    'nothing hits -> 1, 2, 3 then unresolved' => [['wooVariationId' => 999999, 'wooProductId' => 999999, 'sku' => 'NOPE'], [1, 2, 3]],
    'no variation id: starts at step 2' => [['wooVariationId' => null, 'wooProductId' => 101, 'sku' => 'SKU-B'], [2]],
    'no variation id, no product id: only the SKU' => [['wooVariationId' => null, 'wooProductId' => null, 'sku' => 'SKU-C'], [3]],
    'no variation id, product id but no SKU: nothing to ask' => [['wooVariationId' => null, 'wooProductId' => 101, 'sku' => null], []],
    'variation id only, unknown: just step 1' => [['wooVariationId' => 999999, 'wooProductId' => null, 'sku' => null], [1]],
    'no ids, no SKU: no lookup at all' => [['wooVariationId' => null, 'wooProductId' => null, 'sku' => null], []],
]);
