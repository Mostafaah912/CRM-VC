<?php

declare(strict_types=1);

use App\Modules\Core\Enums\AuditActorType;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Orders\Exceptions\RefundSyncException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\Refund;
use App\Modules\Orders\Services\RefundInput;
use App\Modules\Orders\Services\RefundItemInput;
use App\Modules\Orders\Services\RefundService;
use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
| P2-07 — RefundService::sync(): the COMPLETE refund set of ONE order, in one transaction (PRD §10).
| refunded_total = SUM(persisted refunds), RECOMPUTED and SET — never incremented. is_fully_refunded and each
| refund's is_full follow the confirmed rule. Item-level figures are SET from the refund lines of the same
| complete set, attributed by Woo's own link (_refunded_item_id) and never guessed. A refund Woo no longer lists is
| deleted after an audit entry. Real PostgreSQL; all data synthetic.
*/

$GLOBALS['refund_logs'] = [];

beforeEach(function () {
    config(['logging.default' => 'null']);
    $GLOBALS['refund_logs'] = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) {
        $GLOBALS['refund_logs'][] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
    });
});

function refunds(): RefundService
{
    return app(RefundService::class);
}

/** An order with items 9001 (qty 2, 200000 each) and 9002 (qty 1, 100000); total 500000. */
function refundableOrder(int $wooOrderId = 5001, int $total = 500000): Order
{
    $order = Order::factory()->create(['woo_order_id' => $wooOrderId, 'total' => $total, 'status' => 'completed']);
    foreach ([[9001, 2, 200000], [9002, 1, 100000]] as [$wooItemId, $qty, $price]) {
        OrderItem::create([
            'order_id' => $order->id, 'woo_item_id' => $wooItemId + ($wooOrderId - 5001) * 10, 'sku' => "SKU-{$wooItemId}", 'name_snapshot' => "قلم {$wooItemId}",
            'qty' => $qty, 'unit_price' => $price, 'line_subtotal' => $qty * $price, 'line_total' => $qty * $price,
        ]);
    }

    return $order;
}

function refundLine(?int $original, int $quantity = 1, int $amount = 100000, int $wooItemId = 1): RefundItemInput
{
    return new RefundItemInput($wooItemId, $original, $quantity, $amount);
}

/** @param  list<RefundItemInput>  $items */
function refundInput(int $wooRefundId, int $amount, array $items = [], ?string $reason = 'دلیل آزمایشی', string $at = '2026-05-14 10:00:00'): RefundInput
{
    return new RefundInput($wooRefundId, $amount, $reason, CarbonImmutable::parse($at, 'UTC'), $items);
}

function itemRefunds(Order $order): array
{
    return $order->items()->orderBy('woo_item_id')->get()->map(fn (OrderItem $i) => [$i->woo_item_id, $i->refunded_qty, $i->refunded_amount])->all();
}

function refundWarnings(): array
{
    return array_values(array_filter($GLOBALS['refund_logs'], fn (array $l) => $l['level'] === 'warning'));
}

// ================================================================== basic

it('creates a refund on the right order with every field, and leaves other orders alone', function () {
    $order = refundableOrder(5001);
    $other = refundableOrder(5002);

    $summary = refunds()->sync(5001, [refundInput(7001, 100000, [refundLine(9001)], 'سایز مناسب نبود', '2026-05-14 10:00:00')]);

    $refund = Refund::sole();
    expect($refund->order_id)->toBe($order->id)
        ->and([$refund->woo_refund_id, $refund->amount, $refund->reason, $refund->is_full])->toBe([7001, 100000, 'سایز مناسب نبود', false])
        ->and($refund->refunded_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-05-14 10:00:00')
        ->and([$summary->refunds, $summary->removed, $summary->unattributedLines])->toBe([1, 0, 0])
        ->and($other->fresh()->refunded_total)->toBe(0)
        ->and(Refund::where('order_id', $other->id)->count())->toBe(0);
});

it('accepts a refund with no reason and a refund with no lines (amount only)', function () {
    $order = refundableOrder();

    refunds()->sync(5001, [refundInput(7001, 50000, [], null)]);

    expect(Refund::sole()->reason)->toBeNull()
        ->and($order->fresh()->refunded_total)->toBe(50000)
        ->and(itemRefunds($order))->toBe([[9001, 0, 0], [9002, 0, 0]])
        ->and(refundWarnings())->toBe([]);
});

// =========================================================== idempotency

it('is idempotent: syncing the same set again changes nothing and keeps every local id', function () {
    $order = refundableOrder();
    $set = [refundInput(7001, 100000, [refundLine(9001, 1, 100000)]), refundInput(7002, 50000)];
    refunds()->sync(5001, $set);
    $ids = Refund::orderBy('woo_refund_id')->pluck('id')->all();
    $state = fn () => [$order->fresh()->only(['refunded_total', 'is_fully_refunded']), itemRefunds($order), Refund::orderBy('woo_refund_id')->get()->map->only(['id', 'woo_refund_id', 'amount', 'is_full'])->all()];
    $first = $state();

    refunds()->sync(5001, $set);
    refunds()->sync(5001, $set);

    expect($state())->toEqual($first)->and(Refund::orderBy('woo_refund_id')->pluck('id')->all())->toBe($ids)->and(Refund::count())->toBe(2);
});

it('collapses a refund repeated inside one call into one row and counts it once', function () {
    $order = refundableOrder();

    $summary = refunds()->sync(5001, [refundInput(7001, 100000, [refundLine(9001)]), refundInput(7001, 100000, [refundLine(9001)])]);

    expect(Refund::count())->toBe(1)->and($order->fresh()->refunded_total)->toBe(100000)->and($summary->refunds)->toBe(1)
        ->and(itemRefunds($order)[0])->toBe([9001, 1, 100000]);
});

it('updates the existing refund when Woo changed its amount, reason or date', function () {
    $order = refundableOrder();
    refunds()->sync(5001, [refundInput(7001, 100000, [], 'قدیمی', '2026-05-14 10:00:00')]);
    $id = Refund::sole()->id;

    refunds()->sync(5001, [refundInput(7001, 150000, [], 'جدید', '2026-05-16 08:00:00')]);

    $refund = Refund::sole();
    expect([$refund->id, $refund->amount, $refund->reason])->toBe([$id, 150000, 'جدید'])
        ->and($refund->refunded_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-05-16 08:00:00')
        ->and($order->fresh()->refunded_total)->toBe(150000);
});

it('follows changed refund lines: a moved line moves the item figures, the old item goes back to zero', function () {
    $order = refundableOrder();
    refunds()->sync(5001, [refundInput(7001, 100000, [refundLine(9001, 1, 100000)])]);
    expect(itemRefunds($order))->toBe([[9001, 1, 100000], [9002, 0, 0]]);

    refunds()->sync(5001, [refundInput(7001, 100000, [refundLine(9002, 1, 100000)])]);

    expect(itemRefunds($order))->toBe([[9001, 0, 0], [9002, 1, 100000]]);

    refunds()->sync(5001, [refundInput(7001, 100000, [refundLine(9002, 1, 60000), refundLine(9001, 1, 40000, 2)])]);
    expect(itemRefunds($order))->toBe([[9001, 1, 40000], [9002, 1, 60000]]);
});

// ========================================================= multiple refunds

it('aggregates several refunds on one order, and never double-counts when they are all synced again', function () {
    $order = refundableOrder();
    $set = [refundInput(7001, 100000), refundInput(7002, 150000), refundInput(7003, 50000)];

    foreach (range(1, 4) as $ignored) {
        refunds()->sync(5001, $set);
    }

    expect($order->fresh()->refunded_total)->toBe(300000)->and(Refund::count())->toBe(3)
        ->and($order->fresh()->net_revenue)->toBe(200000);
});

it('adds up refunds that overlap on the same order item, per item', function () {
    $order = refundableOrder();
    $set = [
        refundInput(7001, 200000, [refundLine(9001, 1, 200000)]),
        refundInput(7002, 230000, [refundLine(9001, 1, 130000), refundLine(9002, 1, 100000, 2)]),
    ];

    refunds()->sync(5001, $set);
    refunds()->sync(5001, $set);

    expect(itemRefunds($order))->toBe([[9001, 2, 330000], [9002, 1, 100000]])
        ->and($order->fresh()->refunded_total)->toBe(430000);
});

// ======================================================= order aggregates

it('SETS refunded_total from the persisted refunds — a stale or wrong stored value cannot survive', function () {
    $order = refundableOrder();
    Order::query()->update(['refunded_total' => 999999999, 'is_fully_refunded' => true]);

    refunds()->sync(5001, [refundInput(7001, 100000)]);

    expect($order->fresh()->only(['refunded_total', 'is_fully_refunded']))->toBe(['refunded_total' => 100000, 'is_fully_refunded' => false]);
});

it('detects a full refund exactly per the confirmed rule: refunded_total > 0 AND refunded_total >= total', function (int $total, array $amounts, bool $fully) {
    $order = refundableOrder(5001, $total);

    refunds()->sync(5001, array_map(fn (int $a, int $i) => refundInput(7000 + $i, $a), $amounts, array_keys($amounts)));

    expect($order->fresh()->is_fully_refunded)->toBe($fully);
})->with([
    'partial is not full' => [500000, [100000], false],
    'one short of total' => [500000, [499999], false],
    'exactly the total' => [500000, [500000], true],
    'over the total' => [500000, [500001], true],
    'several partials summing to the total' => [500000, [200000, 300000], true],
    'several partials still short' => [500000, [200000, 299999], false],
    'a zero-amount refund only' => [500000, [0], false],
    'no refunds at all' => [500000, [], false],
    'free order with a zero refund is not "fully refunded"' => [0, [0], false],
    'free order with no refunds' => [0, [], false],
]);

it('sets refunds.is_full per refund: amount > 0 AND amount >= order total — a sum of partials makes no single refund full', function () {
    refundableOrder(5001, 500000);

    refunds()->sync(5001, [refundInput(7001, 200000), refundInput(7002, 300000), refundInput(7003, 0)]);
    expect(Refund::orderBy('woo_refund_id')->pluck('is_full')->all())->toBe([false, false, false])
        ->and(Order::sole()->is_fully_refunded)->toBeTrue();

    refunds()->sync(5001, [refundInput(7004, 500000), refundInput(7005, 600000)]);
    expect(Refund::orderBy('woo_refund_id')->pluck('is_full', 'woo_refund_id')->all())->toBe([7004 => true, 7005 => true]);
});

it('recomputes is_full and is_fully_refunded again when the order total has changed since', function () {
    $order = refundableOrder(5001, 500000);
    refunds()->sync(5001, [refundInput(7001, 300000)]);
    expect([Refund::sole()->is_full, $order->fresh()->is_fully_refunded])->toBe([false, false]);

    Order::query()->update(['total' => 300000]);
    refunds()->sync(5001, [refundInput(7001, 300000)]);

    expect([Refund::sole()->is_full, $order->fresh()->is_fully_refunded])->toBe([true, true]);
});

it('keeps net_revenue (generated by the database) consistent with the recomputed refunded_total', function () {
    $order = refundableOrder(5001, 500000);

    refunds()->sync(5001, [refundInput(7001, 120000), refundInput(7002, 30000)]);

    expect($order->fresh()->only(['total', 'refunded_total', 'net_revenue']))->toBe(['total' => 500000, 'refunded_total' => 150000, 'net_revenue' => 350000]);
});

it('works on a soft-deleted order too, without restoring it', function () {
    $order = refundableOrder();
    $order->delete();

    refunds()->sync(5001, [refundInput(7001, 100000)]);

    expect(Order::withTrashed()->find($order->id)->refunded_total)->toBe(100000)->and(Order::withTrashed()->find($order->id)->trashed())->toBeTrue();
});

// ======================================================= item aggregates

it('SETS item refund figures from the refund lines: stale values are overwritten, never added to', function () {
    $order = refundableOrder();
    OrderItem::query()->update(['refunded_qty' => 5, 'refunded_amount' => 999]);

    refunds()->sync(5001, [refundInput(7001, 100000, [refundLine(9001, 1, 100000)])]);
    refunds()->sync(5001, [refundInput(7001, 100000, [refundLine(9001, 1, 100000)])]);

    expect(itemRefunds($order))->toBe([[9001, 1, 100000], [9002, 0, 0]]);
});

it('drops a removed refund\'s lines out of the item figures', function () {
    $order = refundableOrder();
    refunds()->sync(5001, [refundInput(7001, 100000, [refundLine(9001, 1, 100000)]), refundInput(7002, 100000, [refundLine(9002, 1, 100000, 2)])]);
    expect(itemRefunds($order))->toBe([[9001, 1, 100000], [9002, 1, 100000]]);

    refunds()->sync(5001, [refundInput(7001, 100000, [refundLine(9001, 1, 100000)])]);

    expect(itemRefunds($order))->toBe([[9001, 1, 100000], [9002, 0, 0]]);
});

it('leaves a line without Woo\'s link unattributed: item untouched, warned, counted — the amount still counts on the order', function () {
    $order = refundableOrder();

    $summary = refunds()->sync(5001, [refundInput(7001, 100000, [refundLine(null, 1, 100000, 55)])]);

    expect(itemRefunds($order))->toBe([[9001, 0, 0], [9002, 0, 0]])
        ->and($order->fresh()->refunded_total)->toBe(100000)
        ->and($summary->unattributedLines)->toBe(1)
        ->and(refundWarnings())->toHaveCount(1)
        ->and(refundWarnings()[0]['context'])->toMatchArray(['woo_order_id' => 5001, 'woo_refund_id' => 7001, 'woo_refund_item_id' => 55, 'original_woo_item_id' => null]);
});

it('never guesses an attribution from product, variation or SKU: a line with the right SKU but no link changes nothing', function () {
    $order = refundableOrder();
    OrderItem::query()->where('woo_item_id', 9001)->update(['sku' => 'SYN-TEE-001']);

    refunds()->sync(5001, [refundInput(7001, 100000, [refundLine(null, 1, 100000)])]);

    expect(itemRefunds($order)[0])->toBe([9001, 0, 0]);
});

it('warns, without failing, when the link points at an item this order does not have', function () {
    $order = refundableOrder();

    $summary = refunds()->sync(5001, [refundInput(7001, 200000, [refundLine(424242, 1, 100000, 1), refundLine(9002, 1, 100000, 2)])]);

    expect(itemRefunds($order))->toBe([[9001, 0, 0], [9002, 1, 100000]])
        ->and($summary->unattributedLines)->toBe(1)
        ->and(refundWarnings()[0]['context'])->toMatchArray(['original_woo_item_id' => 424242]);
});

// ========================================================== mirror-delete

it('deletes a refund Woo no longer lists, after writing an audit entry with its id, amount and order', function () {
    $order = refundableOrder();
    refunds()->sync(5001, [refundInput(7001, 100000), refundInput(7002, 150000)]);
    $gone = Refund::where('woo_refund_id', 7002)->sole();

    $summary = refunds()->sync(5001, [refundInput(7001, 100000)]);

    expect(Refund::pluck('woo_refund_id')->all())->toBe([7001])
        ->and($order->fresh()->refunded_total)->toBe(100000)
        ->and($summary->removed)->toBe(1);

    $audit = AuditLog::where('action', 'refund.deleted')->sole();
    expect($audit->actor_type)->toBe(AuditActorType::System)
        ->and($audit->auditable_type)->toBe(Refund::class)
        ->and($audit->auditable_id)->toBe($gone->id)
        ->and($audit->source)->toBe('sync')
        ->and($audit->before)->toMatchArray(['woo_refund_id' => 7002, 'amount' => 150000, 'order_id' => $order->id, 'woo_order_id' => 5001])
        ->and($audit->after)->toBeNull();
});

it('removes every refund when Woo lists none, auditing each, and resets the order', function () {
    $order = refundableOrder();
    refunds()->sync(5001, [refundInput(7001, 500000, [refundLine(9001, 2, 400000)]), refundInput(7002, 0)]);
    expect($order->fresh()->is_fully_refunded)->toBeTrue();

    $summary = refunds()->sync(5001, []);

    expect(Refund::count())->toBe(0)
        ->and($order->fresh()->only(['refunded_total', 'is_fully_refunded']))->toBe(['refunded_total' => 0, 'is_fully_refunded' => false])
        ->and(itemRefunds($order))->toBe([[9001, 0, 0], [9002, 0, 0]])
        ->and($summary->removed)->toBe(2)
        ->and(AuditLog::where('action', 'refund.deleted')->count())->toBe(2);
});

it('never touches another order\'s refunds when mirroring one', function () {
    refundableOrder(5001);
    $other = refundableOrder(5002);
    refunds()->sync(5002, [refundInput(8001, 100000)]);

    refunds()->sync(5001, []);

    expect(Refund::where('order_id', $other->id)->count())->toBe(1)->and($other->fresh()->refunded_total)->toBe(100000)
        ->and(AuditLog::where('action', 'refund.deleted')->count())->toBe(0);
});

it('writes no audit entry when nothing was removed', function () {
    refundableOrder();

    refunds()->sync(5001, [refundInput(7001, 100000)]);
    refunds()->sync(5001, [refundInput(7001, 100000)]);

    expect(AuditLog::where('action', 'refund.deleted')->count())->toBe(0);
});

// ================================================== transaction / integrity

it('rolls EVERYTHING back — refunds, deletion, audit entry, item and order figures — when the last step fails', function () {
    $order = refundableOrder();
    refunds()->sync(5001, [refundInput(7001, 100000, [refundLine(9001, 1, 100000)]), refundInput(7002, 150000)]);
    $before = [Refund::orderBy('woo_refund_id')->get()->map->only(['woo_refund_id', 'amount', 'is_full'])->all(), $order->fresh()->only(['refunded_total', 'is_fully_refunded']), itemRefunds($order)];
    Event::listen('eloquent.saving: '.Order::class, fn () => throw new RuntimeException('boom'));

    expect(fn () => refunds()->sync(5001, [refundInput(7001, 200000, [refundLine(9002, 1, 200000)]), refundInput(7003, 70000)]))
        ->toThrow(RuntimeException::class, 'boom');

    expect([Refund::orderBy('woo_refund_id')->get()->map->only(['woo_refund_id', 'amount', 'is_full'])->all(), $order->fresh()->only(['refunded_total', 'is_fully_refunded']), itemRefunds($order)])->toEqual($before)
        ->and(AuditLog::where('action', 'refund.deleted')->count())->toBe(0);
});

it('leaves nothing of a half-written refund set when a later refund cannot be saved', function () {
    $order = refundableOrder();
    $saved = 0;
    Event::listen('eloquent.saving: '.Refund::class, function () use (&$saved) {
        if (++$saved === 2) {
            throw new RuntimeException('second refund fails');
        }
    });

    expect(fn () => refunds()->sync(5001, [refundInput(7001, 100000, [refundLine(9001, 1, 100000)]), refundInput(7002, 50000)]))
        ->toThrow(RuntimeException::class);

    expect(Refund::count())->toBe(0)->and($order->fresh()->refunded_total)->toBe(0)->and(itemRefunds($order))->toBe([[9001, 0, 0], [9002, 0, 0]]);
});

it('refuses an order that is not synced yet, writing nothing', function () {
    expect(function () {
        refunds()->sync(999999, [refundInput(7001, 100000)]);
    })->toThrow(RefundSyncException::class);

    try {
        refunds()->sync(999999, [refundInput(7001, 100000)]);
    } catch (RefundSyncException $e) {
        expect($e->reason)->toBe(RefundSyncException::ORDER_NOT_FOUND)->and($e->wooOrderId)->toBe(999999);
    }

    expect(Refund::count())->toBe(0);
});

it('refuses a Woo refund that is already stored under a different order, and rolls the rest back', function () {
    refundableOrder(5001);
    $other = refundableOrder(5002);
    refunds()->sync(5002, [refundInput(7001, 100000)]);

    try {
        refunds()->sync(5001, [refundInput(7050, 10000), refundInput(7001, 999)]);
        $this->fail('Expected RefundSyncException');
    } catch (RefundSyncException $e) {
        expect($e->reason)->toBe(RefundSyncException::REFUND_UNDER_ANOTHER_ORDER);
    }

    expect(Refund::where('woo_refund_id', 7050)->exists())->toBeFalse()
        ->and(Refund::where('woo_refund_id', 7001)->sole()->only(['order_id', 'amount']))->toBe(['order_id' => $other->id, 'amount' => 100000]);
});

// ================================================================ audit trail

it('never leaves the deletion audit behind if the deletion itself is rolled back', function () {
    refundableOrder();
    refunds()->sync(5001, [refundInput(7001, 100000)]);
    Event::listen('eloquent.deleting: '.Refund::class, fn () => throw new RuntimeException('delete blocked'));

    expect(fn () => refunds()->sync(5001, []))->toThrow(RuntimeException::class);

    expect(Refund::count())->toBe(1)->and(AuditLog::where('action', 'refund.deleted')->count())->toBe(0);
});

// ================================================================== which orders hold refunds (P2-08 refund discovery)

it('says which of the asked-about Woo orders already hold refunds, in the order asked', function () {
    refundableOrder(5001);
    refundableOrder(5002);
    refundableOrder(5003);
    refunds()->sync(5001, [refundInput(7001, 100000)]);
    refunds()->sync(5002, [refundInput(7002, 100000)]);

    expect(refunds()->wooOrderIdsWithRefunds([5003, 5002, 5001, 9999]))->toBe([5002, 5001]);
});

it('reports only the orders it was asked about', function () {
    refundableOrder(5001);
    refundableOrder(5002);
    refunds()->sync(5001, [refundInput(7001, 100000)]);

    expect(refunds()->wooOrderIdsWithRefunds([5002]))->toBe([])
        ->and(refunds()->wooOrderIdsWithRefunds([5001, 5001]))->toBe([5001]);
});

it('asks the database nothing for an empty list', function () {
    DB::enableQueryLog();

    expect(refunds()->wooOrderIdsWithRefunds([]))->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
});

it('stops reporting an order once its last refund was mirrored away', function () {
    refundableOrder(5001);
    refunds()->sync(5001, [refundInput(7001, 100000)]);
    expect(refunds()->wooOrderIdsWithRefunds([5001]))->toBe([5001]);

    refunds()->sync(5001, []);

    expect(refunds()->wooOrderIdsWithRefunds([5001]))->toBe([]);
});

it('sees the refunds of a soft-deleted order, as sync() does', function () {
    $order = refundableOrder(5001);
    refunds()->sync(5001, [refundInput(7001, 100000)]);
    $order->delete();

    expect(refunds()->wooOrderIdsWithRefunds([5001]))->toBe([5001]);
});

it('never writes anything', function () {
    refundableOrder(5001);
    refunds()->sync(5001, [refundInput(7001, 100000)]);
    $before = [Refund::count(), Order::sole()->only(['refunded_total', 'is_fully_refunded']), AuditLog::count()];

    refunds()->wooOrderIdsWithRefunds([5001, 5002]);

    expect([Refund::count(), Order::sole()->only(['refunded_total', 'is_fully_refunded']), AuditLog::count()])->toEqual($before);
});
