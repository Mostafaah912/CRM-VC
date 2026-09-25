<?php

declare(strict_types=1);

use App\Modules\Core\Models\AuditLog;
use App\Modules\Orders\Exceptions\RefundSyncException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\Refund;
use App\Modules\Sync\Exceptions\WooFixtureNotFoundException;
use App\Modules\Sync\Exceptions\WooMappingException;
use App\Modules\Sync\Services\FakeWooClient;
use App\Modules\Sync\Services\OrderSyncService;
use App\Modules\Sync\Services\RefundSyncService;
use App\Modules\Sync\Services\WooClient;
use App\Modules\Sync\Support\WooFixture;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\WooFixtures;
use Tests\Support\WooPayloads;

/*
| P2-07 — the Woo -> Orders refund flow: WooClient (the P2-02 FakeWooClient, no HTTP) reads EVERY page of one
| order's refunds, the P2-03 RefundMapper maps ALL of them, and only then does Orders' RefundService write —
| so a failed or partial fetch can never delete anything. Local order 5001 comes from the recorded orders page.
*/

$GLOBALS['refund_sync_logs'] = [];

beforeEach(function () {
    config(['logging.default' => 'null']);
    Http::preventStrayRequests();
    $GLOBALS['refund_sync_logs'] = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) {
        $GLOBALS['refund_sync_logs'][] = ['level' => $e->level, 'context' => $e->context];
    });
});

function syncedOrder5001(): Order
{
    app()->instance(WooClient::class, WooFixtures::client());
    app(OrderSyncService::class)->syncPage(1);

    return Order::where('woo_order_id', 5001)->sole();
}

function refundSync(?FakeWooClient $fake = null): RefundSyncService
{
    app()->instance(WooClient::class, $fake ?? WooFixtures::client());

    return app(RefundSyncService::class);
}

/** @param  list<list<array<array-key, mixed>>>  $pages  refund payloads per page */
function refundsFake(array $pages): FakeWooClient
{
    $total = count($pages);
    $fixtures = [];

    foreach ($pages as $index => $items) {
        $fixtures[] = new WooFixture('orders/5001/refunds', $index + 1, [], 200, ['X-WP-TotalPages' => (string) $total, 'X-WP-Total' => (string) array_sum(array_map('count', $pages))], $items);
    }

    return new FakeWooClient($fixtures);
}

function itemFigures(Order $order): array
{
    return $order->items()->orderBy('woo_item_id')->get()->map(fn (OrderItem $i) => [$i->woo_item_id, $i->refunded_qty, $i->refunded_amount])->all();
}

it('syncs the recorded refunds of an order: refunds, totals and the linked item', function () {
    $order = syncedOrder5001();
    $fake = WooFixtures::client();

    $summary = refundSync($fake)->syncOrder(5001);

    expect([$summary->refunds, $summary->removed, $summary->unattributedLines])->toBe([2, 0, 0])
        ->and(Refund::orderBy('woo_refund_id')->get()->map->only(['woo_refund_id', 'amount', 'is_full'])->all())->toBe([
            ['woo_refund_id' => 7001, 'amount' => 100000, 'is_full' => false],
            ['woo_refund_id' => 7002, 'amount' => 50000, 'is_full' => false],
        ])
        ->and($order->fresh()->only(['refunded_total', 'is_fully_refunded', 'net_revenue']))->toBe(['refunded_total' => 150000, 'is_fully_refunded' => false, 'net_revenue' => 253880])
        ->and(itemFigures($order))->toBe([[9001, 1, 100000]])
        ->and(collect($fake->requests())->map(fn (array $r) => "{$r['endpoint']}#{$r['page']}")->all())->toBe(['orders/5001/refunds#1']);
    Http::assertNothingSent();
});

it('is idempotent end to end', function () {
    $order = syncedOrder5001();
    $sync = refundSync();
    $sync->syncOrder(5001);
    $state = fn () => [Refund::orderBy('woo_refund_id')->pluck('id')->all(), $order->fresh()->only(['refunded_total', 'is_fully_refunded']), itemFigures($order)];
    $first = $state();

    $sync->syncOrder(5001);
    $sync->syncOrder(5001);

    expect($state())->toEqual($first)->and(Refund::count())->toBe(2);
});

it('survives an order resync: P2-06 carries the item refund figures, and the next refund sync agrees', function () {
    $order = syncedOrder5001();
    refundSync()->syncOrder(5001);

    app()->instance(WooClient::class, WooFixtures::client());
    app(OrderSyncService::class)->syncPage(1);

    expect($order->fresh()->refunded_total)->toBe(150000)->and(itemFigures($order))->toBe([[9001, 1, 100000]]);

    refundSync()->syncOrder(5001);
    expect(itemFigures($order))->toBe([[9001, 1, 100000]]);
});

it('reads every page before writing anything, and follows them all', function () {
    $order = syncedOrder5001();
    $recorded = WooPayloads::items('orders/5001/refunds');

    $summary = refundSync(refundsFake([[$recorded[0]], [$recorded[1]]]))->syncOrder(5001);

    expect($summary->refunds)->toBe(2)->and(Refund::count())->toBe(2)->and($order->fresh()->refunded_total)->toBe(150000);
});

it('never deletes on a partial fetch: a page that cannot be read leaves every stored refund in place', function () {
    $order = syncedOrder5001();
    refundSync()->syncOrder(5001);
    $recorded = WooPayloads::items('orders/5001/refunds');
    // Woo says 2 pages, but page 2 cannot be fetched
    $fake = new FakeWooClient([new WooFixture('orders/5001/refunds', 1, [], 200, ['X-WP-TotalPages' => '2', 'X-WP-Total' => '2'], [$recorded[0]])]);

    expect(fn () => refundSync($fake)->syncOrder(5001))->toThrow(WooFixtureNotFoundException::class);

    expect(Refund::count())->toBe(2)->and($order->fresh()->refunded_total)->toBe(150000)->and(AuditLog::where('action', 'refund.deleted')->count())->toBe(0);
});

it('maps every refund before writing any: one malformed payload writes and deletes nothing', function () {
    $order = syncedOrder5001();
    refundSync()->syncOrder(5001);
    $recorded = WooPayloads::items('orders/5001/refunds');
    $bad = WooPayloads::without($recorded[1], 'amount');

    expect(fn () => refundSync(refundsFake([[$recorded[0], $bad]]))->syncOrder(5001))->toThrow(WooMappingException::class, 'amount');

    expect(Refund::count())->toBe(2)->and($order->fresh()->refunded_total)->toBe(150000);
});

it('mirrors Woo: a refund missing from the full list is deleted and audited', function () {
    $order = syncedOrder5001();
    refundSync()->syncOrder(5001);
    $recorded = WooPayloads::items('orders/5001/refunds');

    $summary = refundSync(refundsFake([[$recorded[0]]]))->syncOrder(5001);

    expect($summary->removed)->toBe(1)
        ->and(Refund::pluck('woo_refund_id')->all())->toBe([7001])
        ->and($order->fresh()->refunded_total)->toBe(100000)
        ->and(AuditLog::where('action', 'refund.deleted')->sole()->before)->toMatchArray(['woo_refund_id' => 7002, 'amount' => 50000, 'woo_order_id' => 5001]);
});

it('leaves a refund line without Woo\'s link unattributed and warns, with the amount still on the order', function () {
    $order = syncedOrder5001();
    $recorded = WooPayloads::items('orders/5001/refunds');
    $noLink = WooPayloads::without($recorded[0], 'line_items.0.meta_data');

    $summary = refundSync(refundsFake([[$noLink]]))->syncOrder(5001);

    expect($summary->unattributedLines)->toBe(1)
        ->and(itemFigures($order))->toBe([[9001, 0, 0]])
        ->and($order->fresh()->refunded_total)->toBe(100000)
        ->and(array_filter($GLOBALS['refund_sync_logs'], fn (array $l) => $l['level'] === 'warning' && isset($l['context']['woo_refund_id'])))->toHaveCount(1);
});

it('refuses an order that is not synced yet, before writing anything', function () {
    expect(fn () => refundSync()->syncOrder(5001))->toThrow(RefundSyncException::class);

    expect(Refund::count())->toBe(0);
});

it('rejects an order id that is not a real id', function (int $id) {
    refundSync()->syncOrder($id);
})->with([[0], [-5]])->throws(InvalidArgumentException::class);

it('reads Woo only through the WooClient contract', function () {
    $types = array_map(fn (ReflectionParameter $p) => (string) $p->getType(), (new ReflectionClass(RefundSyncService::class))->getConstructor()?->getParameters() ?? []);

    expect($types)->toContain(WooClient::class);
});
