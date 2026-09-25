<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerShowService;
use App\Modules\Customers\Services\CustomerTimelineService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/*
| P3-03 — CustomerShowService::show(): everything the Customer 360 page shows, for ONE customer, in six queries (customer,
| customer_metrics, last five orders, last five products, order count and — since P3-04 — the first page of the timeline): the
| budget, exactly (SEVEN when a customer_metrics row exists — P4-08's isMetricsStale() check). Read-only; the phone is always
| masked; no email or name leaves.
*/

const CSV_PHONE = '989121234567';

beforeEach(function () {
    config(['logging.default' => 'null']);
    $this->customer = Customer::factory()->create([
        'phone_normalized' => CSV_PHONE,
        'display_name' => 'ZZ Display Name',
        'first_name' => 'ZZFirst',
        'last_name' => 'ZZLast',
        'email' => 'zz-show@example.test',
        'province' => 'تهران',
        'city' => 'تهران',
    ]);
});

function csvOrder(int $customerId, string $orderedAt, array $overrides = []): int
{
    return (int) DB::table('orders')->insertGetId([
        'woo_order_id' => random_int(1, 900_000_000),
        'customer_id' => $customerId,
        'status' => 'completed',
        'is_realized' => true,
        'total' => 1_000_000,
        'ordered_at' => $orderedAt,
        ...$overrides,
    ]);
}

function csvItem(int $orderId, string $name, ?int $productId = null, ?string $sku = 'SKU-1', int $qty = 1): void
{
    DB::table('order_items')->insert([
        'order_id' => $orderId,
        'product_id' => $productId,
        'sku' => $sku,
        'name_snapshot' => $name,
        'qty' => $qty,
    ]);
}

function csvMetrics(int $customerId, array $overrides = []): void
{
    DB::table('customer_metrics')->insert([
        'customer_id' => $customerId,
        'total_orders' => 3,
        'total_revenue' => 4_500_000,
        'aov' => 1_500_000,
        'first_order_at' => '2026-01-01 08:00:00+00',
        'last_order_at' => '2026-03-20 20:30:00+00',
        'r_score' => 5,
        'f_score' => 3,
        'm_score' => 4,
        'clv_estimated' => 9_000_000,
        'clv_confidence' => 'medium',
        'churn_risk_score' => '72.50',
        'churn_risk_level' => 'high',
        'churn_reason' => 'فاصله‌ی خرید از چرخه‌ی معمول بیشتر شده',
        'computed_at' => '2026-09-20 00:00:00+00',
        ...$overrides,
    ]);
}

/** @return list<string> the SQL of every query run by $callback */
function csvQueries(Closure $callback): array
{
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });
    $callback();

    return $sql;
}

function csvShow(int $id): array
{
    return app(CustomerShowService::class)->show($id)->toArray();
}

// ================================================================== query budget

it('loads a fully populated customer in exactly seven queries with metrics — the budget, and not one more', function () {
    $product = Product::factory()->create();
    csvMetrics($this->customer->id);

    foreach (range(1, 7) as $day) {
        $order = csvOrder($this->customer->id, "2026-03-0{$day} 10:00:00+00");
        csvItem($order, 'Shirt', $product->id);
    }

    $queries = csvQueries(fn () => csvShow($this->customer->id));

    // Six is P3-03's own budget; P4-08 adds a seventh — the latest completed metric_run — only when
    // a customer_metrics row exists at all (see the next test).
    expect($queries)->toHaveCount(7)
        ->and(count($queries))->toBeLessThanOrEqual(7);
});

it('spends no more queries on an empty customer, or one without a metrics row — the 7th is skipped entirely', function () {
    expect(csvQueries(fn () => csvShow($this->customer->id)))->toHaveCount(6);
});

it('does not grow with the history: forty orders and forty events cost the same six queries as none', function () {
    $product = Product::factory()->create();

    foreach (range(1, 40) as $i) {
        csvItem(csvOrder($this->customer->id, CarbonImmutable::parse('2026-01-01', 'UTC')->addDays($i)->toDateTimeString().'+00'), "Item {$i}", $product->id);
    }

    foreach (range(1, 40) as $i) {
        DB::table('customer_events')->insert(['customer_id' => $this->customer->id, 'event_type' => 'note_added', 'happened_at' => CarbonImmutable::parse('2026-01-01', 'UTC')->addDays($i)->toDateTimeString().'+00']);
    }

    expect(csvQueries(fn () => csvShow($this->customer->id)))->toHaveCount(6);
});

it('never touches a table the profile does not need', function () {
    csvMetrics($this->customer->id);
    $tables = 'customers|customer_metrics|orders|order_items|customer_events|metric_runs';

    $queries = csvQueries(fn () => csvShow($this->customer->id));

    expect(array_filter($queries, fn (string $sql) => preg_match('/\b(from|join)\s+"?('.$tables.')"?(\s|$)/i', $sql) !== 1))->toBe([]);
});

// ================================================================== customer + phone

it('shows the customer with the phone masked and the first-seen date in Jalali', function () {
    // 2026-03-20 20:30 UTC = 1405/01/01 00:00 Tehran
    $this->customer->forceFill(['first_seen_at' => '2026-03-20 20:30:00+00'])->save();

    expect(csvShow($this->customer->id)['customer'])->toBe([
        'id' => $this->customer->id,
        'display_name' => 'ZZ Display Name',
        'phone' => '********4567',
        'status' => 'active',
        'lifecycle_stage' => 'prospect',
        'province' => 'تهران',
        'city' => 'تهران',
        'first_seen_at' => '1405/01/01',
    ]);
});

it('carries no email, no first/last name and no phone but the masked one — anywhere in the profile', function () {
    csvMetrics($this->customer->id);
    csvItem(csvOrder($this->customer->id, '2026-03-01 10:00:00+00'), 'Shirt');

    $json = json_encode(csvShow($this->customer->id));

    expect($json)->not->toContain(CSV_PHONE)
        ->not->toContain('zz-show@example.test')
        ->not->toContain('ZZFirst')
        ->not->toContain('ZZLast')
        ->not->toContain('phone_normalized')
        ->not->toContain('email');
});

it('leaves a missing display name, province, city and first-seen date as null', function () {
    $bare = Customer::factory()->create(['display_name' => null, 'province' => null, 'city' => null, 'first_seen_at' => null]);

    expect(csvShow($bare->id)['customer'])->toMatchArray(['display_name' => null, 'province' => null, 'city' => null, 'first_seen_at' => null]);
});

// ================================================================== soft delete / missing

it('refuses a soft-deleted customer as not found', function () {
    $this->customer->delete();

    csvShow($this->customer->id);
})->throws(ModelNotFoundException::class);

it('refuses an id that does not exist as not found', function () {
    csvShow(987_654_321);
})->throws(ModelNotFoundException::class);

// ================================================================== metrics

it('gives null metrics — no row of zeros — when customer_metrics has no row for the customer', function () {
    $profile = csvShow($this->customer->id);

    expect($profile['metrics'])->toBeNull();
});

it('reads the metrics row as stored: money as ints, the score as an exact decimal string, dates in Jalali/Tehran time', function () {
    csvMetrics($this->customer->id, [
        'rfm_score' => '534', 'rfm_segment' => 'loyal', 'clv_historical' => 765_000,
        'expected_next_order_at' => '2026-05-01 08:30:00+00',
    ]);

    expect(csvShow($this->customer->id)['metrics'])->toBe([
        'total_orders' => 3,
        'total_revenue' => 4_500_000,
        'aov' => 1_500_000,
        'first_order_at' => '1404/10/11 11:30:00',
        'last_order_at' => '1405/01/01 00:00:00',
        'r_score' => 5,
        'f_score' => 3,
        'm_score' => 4,
        'rfm_score' => '534',
        'rfm_segment' => 'loyal',
        'clv_historical' => 765_000,
        'clv_estimated' => 9_000_000,
        'clv_confidence' => 'medium',
        'churn_risk_score' => '72.50',
        'churn_risk_level' => 'high',
        'churn_reason' => 'فاصله‌ی خرید از چرخه‌ی معمول بیشتر شده',
        'expected_next_order_at' => '1405/02/11 12:00:00',
        'expected_next_order_at_iso' => '2026-05-01T08:30:00Z',
        'computed_at' => '1405/06/29 03:30:00',
        'metrics_stale' => false,
    ]);
});

it('keeps every nullable metric null: no clv for a one-order customer, no scores before they are computed', function () {
    csvMetrics($this->customer->id, [
        'total_orders' => 1, 'clv_estimated' => null, 'clv_confidence' => null, 'r_score' => null, 'f_score' => null, 'm_score' => null,
        'churn_risk_score' => null, 'churn_risk_level' => null, 'churn_reason' => null, 'last_order_at' => null, 'first_order_at' => null,
    ]);

    expect(csvShow($this->customer->id)['metrics'])->toMatchArray([
        'total_orders' => 1, 'clv_estimated' => null, 'clv_confidence' => null, 'r_score' => null, 'f_score' => null, 'm_score' => null,
        'churn_risk_score' => null, 'churn_risk_level' => null, 'churn_reason' => null, 'last_order_at' => null, 'first_order_at' => null,
    ]);
});

it('does not read another customer\'s metrics', function () {
    csvMetrics(Customer::factory()->create()->id, ['total_revenue' => 999]);

    expect(csvShow($this->customer->id)['metrics'])->toBeNull();
});

it('leaves clv_estimated null (never zero) for a single-order customer, alongside a real clv_historical', function () {
    csvMetrics($this->customer->id, [
        'total_orders' => 1, 'clv_historical' => 84_915, 'clv_estimated' => null, 'clv_confidence' => 'low',
    ]);

    $metrics = csvShow($this->customer->id)['metrics'];

    expect($metrics['clv_estimated'])->toBeNull()
        ->and($metrics['clv_historical'])->toBe(84_915)
        ->and($metrics['clv_confidence'])->toBe('low');
});

// ================================================================== metrics_stale (P4-08)

function csvMetricRun(string $status, ?string $finishedAt): void
{
    DB::table('metric_runs')->insert([
        'mode' => 'full', 'status' => $status, 'started_at' => now(), 'finished_at' => $finishedAt,
        // isMetricsStale() identifies "completed" structurally (finished_at set, no error) — a failed
        // run in a test must set error too, or it would be indistinguishable from a completed one.
        'error' => $status === 'failed' ? 'x' : null,
    ]);
}

it('flags metrics_stale when a later metric run completed after this row was computed', function () {
    csvMetrics($this->customer->id, ['computed_at' => '2026-09-01 00:00:00+00']);
    csvMetricRun('completed', '2026-09-15 00:00:00+00');

    expect(csvShow($this->customer->id)['metrics']['metrics_stale'])->toBeTrue();
});

it('does not flag metrics_stale when no run has completed after this row was computed', function () {
    csvMetrics($this->customer->id, ['computed_at' => '2026-09-20 00:00:00+00']);
    csvMetricRun('completed', '2026-09-01 00:00:00+00'); // older than computed_at
    csvMetricRun('failed', '2026-09-25 00:00:00+00'); // newer, but not completed — must not count

    expect(csvShow($this->customer->id)['metrics']['metrics_stale'])->toBeFalse();
});

it('does not flag metrics_stale when no metric run has ever completed', function () {
    csvMetrics($this->customer->id);

    expect(csvShow($this->customer->id)['metrics']['metrics_stale'])->toBeFalse();
});

// ================================================================== orders

it('gives empty lists and a zero count for a customer with no orders', function () {
    $profile = csvShow($this->customer->id);

    expect($profile['recent_orders'])->toBe([])
        ->and($profile['orders_total'])->toBe(0)
        ->and($profile['recent_products'])->toBe([])
        ->and($profile['timeline'])->toBe(['data' => [], 'next_cursor' => null, 'has_more' => false]);
});

it('lists the last five orders newest first, and counts all of them', function () {
    foreach (range(1, 7) as $day) {
        csvOrder($this->customer->id, "2026-03-0{$day} 10:00:00+00", ['woo_order_id' => 1000 + $day, 'total' => $day * 100_000]);
    }

    $profile = csvShow($this->customer->id);

    expect(array_column($profile['recent_orders'], 'woo_order_id'))->toBe([1007, 1006, 1005, 1004, 1003])
        ->and($profile['orders_total'])->toBe(7)
        ->and($profile['recent_orders'][0])->toBe([
            'woo_order_id' => 1007,
            'number' => null,
            'status' => 'completed',
            'total' => 700_000,
            'ordered_at' => '1404/12/16 13:30:00',
        ]);
});

it('breaks a tie between two orders placed in the same second by id, newest id first', function () {
    csvOrder($this->customer->id, '2026-03-01 10:00:00+00', ['woo_order_id' => 2001]);
    csvOrder($this->customer->id, '2026-03-01 10:00:00+00', ['woo_order_id' => 2002]);

    expect(array_column(csvShow($this->customer->id)['recent_orders'], 'woo_order_id'))->toBe([2002, 2001]);
});

it('lists orders of every status — the page shows what the customer did, not only what counted as revenue', function () {
    csvOrder($this->customer->id, '2026-03-01 10:00:00+00', ['status' => 'cancelled', 'is_realized' => false]);

    expect(csvShow($this->customer->id)['recent_orders'][0]['status'])->toBe('cancelled');
});

it('leaves out another customer\'s orders and any soft-deleted order, from the list and the count', function () {
    csvOrder($this->customer->id, '2026-03-01 10:00:00+00', ['woo_order_id' => 3001]);
    csvOrder($this->customer->id, '2026-03-02 10:00:00+00', ['woo_order_id' => 3002, 'deleted_at' => '2026-03-03 00:00:00+00']);
    csvOrder(Customer::factory()->create()->id, '2026-03-04 10:00:00+00', ['woo_order_id' => 3003]);

    $profile = csvShow($this->customer->id);

    expect(array_column($profile['recent_orders'], 'woo_order_id'))->toBe([3001])
        ->and($profile['orders_total'])->toBe(1);
});

// ================================================================== products

it('lists each product once with how many orders bought it and when last, most recent first', function () {
    $shirt = Product::factory()->create();
    $coat = Product::factory()->create();

    csvItem(csvOrder($this->customer->id, '2026-03-01 10:00:00+00'), 'Shirt', $shirt->id, 'SH-1');
    csvItem(csvOrder($this->customer->id, '2026-03-05 10:00:00+00'), 'Shirt', $shirt->id, 'SH-1');
    csvItem(csvOrder($this->customer->id, '2026-03-03 10:00:00+00'), 'Coat', $coat->id, 'CO-1');

    expect(csvShow($this->customer->id)['recent_products'])->toBe([
        ['name' => 'Shirt', 'sku' => 'SH-1', 'purchase_count' => 2, 'last_purchased_at' => '1404/12/14 13:30:00'],
        ['name' => 'Coat', 'sku' => 'CO-1', 'purchase_count' => 1, 'last_purchased_at' => '1404/12/12 13:30:00'],
    ]);
});

it('counts a product once per order, even when one order holds it on two lines', function () {
    $shirt = Product::factory()->create();
    $order = csvOrder($this->customer->id, '2026-03-01 10:00:00+00');
    csvItem($order, 'Shirt M', $shirt->id, 'SH-M');
    csvItem($order, 'Shirt L', $shirt->id, 'SH-L');

    expect(csvShow($this->customer->id)['recent_products'])->toHaveCount(1)
        ->and(csvShow($this->customer->id)['recent_products'][0]['purchase_count'])->toBe(1);
});

it('shows the name from the most recent purchase when a product was renamed between orders', function () {
    $shirt = Product::factory()->create();
    csvItem(csvOrder($this->customer->id, '2026-03-01 10:00:00+00'), 'Old name', $shirt->id);
    csvItem(csvOrder($this->customer->id, '2026-03-09 10:00:00+00'), 'New name', $shirt->id);

    expect(csvShow($this->customer->id)['recent_products'][0]['name'])->toBe('New name');
});

it('keeps an item whose product could not be resolved, grouped by the name it was sold under', function () {
    csvItem(csvOrder($this->customer->id, '2026-03-01 10:00:00+00'), 'Ghost item', null, null);
    csvItem(csvOrder($this->customer->id, '2026-03-02 10:00:00+00'), 'Ghost item', null, null);
    csvItem(csvOrder($this->customer->id, '2026-03-03 10:00:00+00'), 'Other ghost', null, null);

    $products = csvShow($this->customer->id)['recent_products'];

    expect(array_column($products, 'name'))->toBe(['Other ghost', 'Ghost item'])
        ->and(array_column($products, 'purchase_count'))->toBe([1, 2])
        ->and($products[0]['sku'])->toBeNull();
});

it('counts only realized orders as purchases, however many the customer placed', function () {
    $shirt = Product::factory()->create();
    csvItem(csvOrder($this->customer->id, '2026-03-01 10:00:00+00', ['status' => 'cancelled', 'is_realized' => false]), 'Shirt', $shirt->id);

    expect(csvShow($this->customer->id)['recent_products'])->toBe([]);
});

it('takes "realized" from the stored flag, not from a status string in the code', function () {
    config(['woo.realized_statuses' => ['nothing-real']]);
    $shirt = Product::factory()->create();
    csvItem(csvOrder($this->customer->id, '2026-03-01 10:00:00+00', ['status' => 'completed', 'is_realized' => true]), 'Shirt', $shirt->id);

    expect(csvShow($this->customer->id)['recent_products'])->toHaveCount(1);
});

it('lists at most five products and leaves out another customer\'s and soft-deleted orders\' items', function () {
    foreach (range(1, 7) as $i) {
        csvItem(csvOrder($this->customer->id, "2026-03-0{$i} 10:00:00+00"), "Item {$i}", Product::factory()->create()->id);
    }
    csvItem(csvOrder($this->customer->id, '2026-03-09 10:00:00+00', ['deleted_at' => '2026-03-10 00:00:00+00']), 'Deleted order item', Product::factory()->create()->id);
    csvItem(csvOrder(Customer::factory()->create()->id, '2026-03-09 10:00:00+00'), 'Somebody else item', Product::factory()->create()->id);

    $names = array_column(csvShow($this->customer->id)['recent_products'], 'name');

    expect($names)->toBe(['Item 7', 'Item 6', 'Item 5', 'Item 4', 'Item 3']);
});

// ================================================================== read-only

it('writes nothing and logs nothing: viewing a profile changes no row', function () {
    csvMetrics($this->customer->id);
    csvItem(csvOrder($this->customer->id, '2026-03-01 10:00:00+00'), 'Shirt');
    $tables = ['customers', 'customer_metrics', 'orders', 'order_items', 'audit_logs', 'phone_reveal_logs'];
    $count = fn () => array_map(fn (string $t) => DB::table($t)->count(), $tables);
    $before = $count();

    csvShow($this->customer->id);

    expect($count())->toBe($before);
});

// ================================================================== timeline (P3-04)

it('carries the first page of the timeline: the newest 20 events with a cursor for the rest, exactly what the timeline endpoint gives', function () {
    foreach (range(1, 25) as $i) {
        DB::table('customer_events')->insert([
            'customer_id' => $this->customer->id, 'event_type' => 'order_placed', 'happened_at' => sprintf('2026-03-%02d 10:00:00+00', $i),
            'payload' => json_encode(['woo_order_id' => $i, 'email' => 'leak@example.test']),
        ]);
    }

    $timeline = csvShow($this->customer->id)['timeline'];

    expect($timeline['data'])->toHaveCount(20)
        ->and($timeline['has_more'])->toBeTrue()
        ->and($timeline['next_cursor'])->toBeString()
        ->and($timeline['data'][0]['payload'])->toBe(['woo_order_id' => 25])
        ->and($timeline)->toBe(app(CustomerTimelineService::class)->pageFor($this->customer->id)->toArray())
        ->and(json_encode($timeline))->not->toContain('leak@example.test');
});

it('leaves out another customer\'s events from the timeline it carries', function () {
    DB::table('customer_events')->insert(['customer_id' => Customer::factory()->create()->id, 'event_type' => 'note_added', 'happened_at' => '2026-03-01 10:00:00+00']);

    expect(csvShow($this->customer->id)['timeline']['data'])->toBe([]);
});
