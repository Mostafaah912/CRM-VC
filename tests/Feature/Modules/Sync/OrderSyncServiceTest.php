<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariation;
use App\Modules\Customers\Enums\IdentitySource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerIdentity;
use App\Modules\Orders\Exceptions\OrderCustomerUnresolvedException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\Refund;
use App\Modules\Orders\Services\RefundInput;
use App\Modules\Orders\Services\RefundService;
use App\Modules\Sync\Exceptions\WooCurrencyMismatchException;
use App\Modules\Sync\Exceptions\WooMappingException;
use App\Modules\Sync\Services\FakeWooClient;
use App\Modules\Sync\Services\OrderSyncService;
use App\Modules\Sync\Services\WooClient;
use App\Modules\Sync\Support\SyncWindow;
use App\Modules\Sync\Support\WooFixture;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\WooFixtures;
use Tests\Support\WooPayloads;

/*
| P2-06 — the Woo -> Orders flow: WooClient (the P2-02 FakeWooClient, no HTTP) -> P2-03 OrderMapper -> Orders'
| public OrderService. Only the recorded fixtures feed it. One page per call: paging, windows and cursors
| belong to P2-08, and this never fetches or writes refunds (P2-07).
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
    Http::preventStrayRequests();
});

function orderSync(?FakeWooClient $fake = null): OrderSyncService
{
    app()->instance(WooClient::class, $fake ?? WooFixtures::client());

    return app(OrderSyncService::class);
}

/** @param  list<array<array-key, mixed>>  $orders */
function ordersFake(array $orders): FakeWooClient
{
    return new FakeWooClient([new WooFixture('orders', 1, [], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => (string) count($orders)], $orders)]);
}

it('syncs the first recorded page: orders, items, customers and identities', function () {
    $fake = WooFixtures::client();

    $result = orderSync($fake)->syncPage(1);

    expect([$result->orders, $result->items, $result->hasMore])->toBe([2, 2, true])
        ->and(Order::orderBy('woo_order_id')->pluck('woo_order_id')->all())->toBe([5001, 5002])
        ->and(Order::where('woo_order_id', 5001)->sole()->only(['status', 'is_realized', 'total', 'subtotal']))->toBe(['status' => 'completed', 'is_realized' => true, 'total' => 403880, 'subtotal' => 403880])
        ->and(Order::where('woo_order_id', 5002)->sole()->only(['status', 'is_realized', 'total', 'discount_total', 'subtotal']))->toBe(['status' => 'processing', 'is_realized' => true, 'total' => 1250000, 'discount_total' => 50000, 'subtotal' => 1300000])
        ->and(Customer::pluck('phone_normalized')->sort()->values()->all())->toBe(['989000000001', '989000000002'])
        ->and(CustomerIdentity::where('source', IdentitySource::WooUser)->pluck('source_id')->sort()->values()->all())->toBe(['11', '12'])
        ->and($fake->requests())->toHaveCount(1)
        ->and($fake->requests()[0]['endpoint'])->toBe('orders');
    Http::assertNothingSent();
});

it('syncs the last page: a cancelled order is stored, is not realized, and its vanished product does not stop it', function () {
    $result = orderSync()->syncPage(2);

    $order = Order::sole();
    $item = OrderItem::sole();
    expect([$result->orders, $result->items, $result->hasMore])->toBe([1, 1, false])
        ->and($order->only(['status', 'is_realized']))->toBe(['status' => 'cancelled', 'is_realized' => false])
        ->and($order->paid_at)->toBeNull()
        ->and([$item->product_id, $item->variation_id, $item->sku])->toBe([null, null, null])
        ->and($item->name_snapshot)->toBe('محصول حذف‌شده آزمایشی')
        ->and($item->qty)->toBe(2)
        ->and($item->line_total)->toBe(560000);
});

it('is idempotent end to end: syncing a page twice leaves the same database state', function () {
    $sync = orderSync();
    $sync->syncPage(1);
    $sync->syncPage(2);
    $state = fn () => [
        Order::orderBy('woo_order_id')->get()->map->only(['id', 'woo_order_id', 'customer_id', 'status', 'total', 'subtotal'])->all(),
        OrderItem::orderBy('order_id')->orderBy('woo_item_id')->get()->map->only(['woo_item_id', 'sku', 'name_snapshot', 'qty', 'line_total'])->all(),
        Customer::count(), CustomerIdentity::count(),
    ];
    $first = $state();

    $sync->syncPage(1);
    $sync->syncPage(2);

    expect($state())->toEqual($first)->and(Order::count())->toBe(3)->and(OrderItem::count())->toBe(3);
});

it('resolves an item against the catalog through the four steps (here step 1: the recorded variation id)', function () {
    $jacket = Product::factory()->create(['woo_product_id' => 102]);
    $variation = ProductVariation::factory()->create(['product_id' => $jacket->id, 'woo_variation_id' => 1021, 'sku' => 'SYN-JKT-002-M']);

    orderSync()->syncPage(1);

    $item = OrderItem::where('order_id', Order::where('woo_order_id', 5002)->sole()->id)->sole();
    expect([$item->variation_id, $item->product_id])->toBe([$variation->id, $jacket->id]);
});

it('leaves a simple product\'s line unresolved today: the catalog stores SKUs on variations only (a P2-05 limit, see ARCHITECTURE.md)', function () {
    Product::factory()->create(['woo_product_id' => 101]);

    orderSync()->syncPage(1);

    $item = OrderItem::where('order_id', Order::where('woo_order_id', 5001)->sole()->id)->sole();
    expect([$item->product_id, $item->variation_id, $item->sku])->toBe([null, null, 'SYN-TEE-001']);
});

it('does not let a Woo variation id of 0 match a local variation stored with woo_variation_id 0', function () {
    $p = Product::factory()->create(['woo_product_id' => 555]);
    ProductVariation::factory()->create(['product_id' => $p->id, 'woo_variation_id' => 0, 'sku' => 'ZERO-TRAP']);

    orderSync()->syncPage(1);

    expect(OrderItem::whereNotNull('variation_id')->count())->toBe(0);
});

it('hands the frozen window straight to the client and adds no cursor logic of its own', function () {
    $fake = WooFixtures::client();
    $window = SyncWindow::freeze(null, 10);

    orderSync($fake)->syncPage(1, $window);

    expect($fake->requests()[0]['window'])->toBe($window);
});

it('refuses an order in a currency other than the store unit before writing anything', function () {
    $usd = WooPayloads::set(WooPayloads::items('orders')[0], 'currency', 'USD');

    expect(fn () => orderSync(ordersFake([$usd]))->syncPage(1))->toThrow(WooCurrencyMismatchException::class, 'USD');

    expect(Order::count())->toBe(0)->and(Customer::count())->toBe(0);
});

it('takes the store currency from config, not from code', function () {
    config(['woo.currency' => 'USD']);
    $usd = WooPayloads::set(WooPayloads::items('orders')[0], 'currency', 'USD');

    orderSync(ordersFake([$usd]))->syncPage(1);

    expect(Order::count())->toBe(1);
});

it('has the store currency configured as IRT (Toman), as P0-00 verified', function () {
    expect(config('woo.currency'))->toBe('IRT');
});

it('fails loudly on a malformed order, keeping the orders already committed and writing nothing of it', function () {
    $good = WooPayloads::items('orders')[0];
    $bad = WooPayloads::without(WooPayloads::items('orders')[1], 'total');

    expect(fn () => orderSync(ordersFake([$good, $bad]))->syncPage(1))->toThrow(WooMappingException::class, 'total');

    expect(Order::pluck('woo_order_id')->all())->toBe([5001]);
});

it('stops on an order with no usable phone without losing the ones before it, and leaks no phone', function () {
    $good = WooPayloads::items('orders')[0];
    $noPhone = WooPayloads::set(WooPayloads::items('orders')[1], 'billing.phone', '12345');

    try {
        orderSync(ordersFake([$good, $noPhone]))->syncPage(1);
        $this->fail('Expected OrderCustomerUnresolvedException');
    } catch (OrderCustomerUnresolvedException $e) {
        expect($e->wooOrderId)->toBe(5002)->and($e->getMessage())->not->toContain('12345');
    }

    expect(Order::pluck('woo_order_id')->all())->toBe([5001]);
});

it('does not touch refunds: syncing an order never writes one', function () {
    orderSync()->syncPage(1);

    expect(Refund::count())->toBe(0);
});

it('reads Woo only through the WooClient contract', function () {
    $types = array_map(fn (ReflectionParameter $p) => (string) $p->getType(), (new ReflectionClass(OrderSyncService::class))->getConstructor()?->getParameters() ?? []);

    expect($types)->toContain(WooClient::class);
});

// ================================================================== refund discovery (P2-08, R-b)
// The page reports which of its orders need their refunds re-read: those whose payload lists refunds
// (refundsCount > 0) UNION those that already hold refund rows locally (so a refund Woo deleted is
// mirrored away). It only REPORTS — it never fetches or writes refunds.

/** A recorded order payload that lists $count refund summaries (Woo's shape: id, reason, total). */
function orderListingRefunds(int $recordedIndex, int $count): array
{
    $summaries = $count === 0 ? [] : array_map(
        fn (int $n): array => ['id' => 7000 + $n, 'reason' => '', 'total' => '-1000'],
        range(1, $count),
    );

    return WooPayloads::set(WooPayloads::items('orders')[$recordedIndex], 'refunds', $summaries);
}

function storeLocalRefund(int $wooOrderId, int $wooRefundId = 8001): void
{
    app(RefundService::class)->sync($wooOrderId, [new RefundInput($wooRefundId, 1000, null, CarbonImmutable::parse('2026-05-14 10:00:00', 'UTC'), [])]);
}

it('reports no order for the recorded pages: none lists refunds and none holds any', function () {
    expect(orderSync()->syncPage(1)->refundOrderIds)->toBe([])
        ->and(orderSync()->syncPage(2)->refundOrderIds)->toBe([]);
});

it('reports the orders whose payload lists refunds, in page order', function () {
    $result = orderSync(ordersFake([orderListingRefunds(0, 1), orderListingRefunds(1, 2)]))->syncPage(1);

    expect($result->refundOrderIds)->toBe([5001, 5002]);
});

it('reports only the orders that list refunds, not the others on the page', function () {
    $result = orderSync(ordersFake([orderListingRefunds(0, 0), orderListingRefunds(1, 3)]))->syncPage(1);

    expect($result->refundOrderIds)->toBe([5002]);
});

it('reports an order whose payload now lists none but which still holds refunds locally (a Woo deletion to mirror)', function () {
    orderSync(ordersFake([orderListingRefunds(0, 0), orderListingRefunds(1, 0)]))->syncPage(1);
    storeLocalRefund(5002);

    $result = orderSync(ordersFake([orderListingRefunds(0, 0), orderListingRefunds(1, 0)]))->syncPage(1);

    expect($result->refundOrderIds)->toBe([5002]);
});

it('reports an order once when both the payload and the local rows say it has refunds', function () {
    orderSync(ordersFake([orderListingRefunds(0, 1)]))->syncPage(1);
    storeLocalRefund(5001);

    $result = orderSync(ordersFake([orderListingRefunds(0, 1)]))->syncPage(1);

    expect($result->refundOrderIds)->toBe([5001]);
});

it('does not report an order whose payload has no refunds key (unknown) and which holds none', function () {
    $noKey = WooPayloads::without(WooPayloads::items('orders')[0], 'refunds');

    expect(orderSync(ordersFake([$noKey]))->syncPage(1)->refundOrderIds)->toBe([]);
});

it('does not report an order with local refunds that is not on this page', function () {
    orderSync()->syncPage(1);
    storeLocalRefund(5001);

    $result = orderSync()->syncPage(2);

    expect($result->refundOrderIds)->toBe([]);
});

it('keeps the union in page order, each order once', function () {
    orderSync(ordersFake([orderListingRefunds(0, 0), orderListingRefunds(1, 0)]))->syncPage(1);
    storeLocalRefund(5001);
    $third = WooPayloads::items('orders', 2)[0];

    $result = orderSync(ordersFake([orderListingRefunds(1, 2), orderListingRefunds(0, 0), WooPayloads::set($third, 'refunds', [['id' => 7100, 'reason' => '', 'total' => '-1']])]))->syncPage(1);

    expect($result->refundOrderIds)->toBe([5002, 5001, 5003]);
});

it('only reports: it neither fetches refunds nor writes any', function () {
    $fake = ordersFake([orderListingRefunds(0, 2)]);

    orderSync($fake)->syncPage(1);

    expect(array_column($fake->requests(), 'endpoint'))->toBe(['orders'])
        ->and(Refund::count())->toBe(0);
});
