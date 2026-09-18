<?php

declare(strict_types=1);

use App\Support\PhoneNormalizer;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\DemoSnapshot;

/*
| PRD §24: 50 customers = 20 one-time + 15 repeat + 8 loyal + 4 at-risk + 3 with refunds.
| Groups are addressed by demo phone index (1..50) so nothing here depends on row ids.
*/
function demoCustomerIds(): array
{
    $ids = [];
    foreach (DB::table('customers')->orderBy('id')->get(['id', 'phone_normalized']) as $row) {
        $ids[(int) substr($row->phone_normalized, -6)] = $row->id;
    }

    return $ids;
}

/** @return array<int, int> customer index => number of orders that count (realized, not fully refunded, not deleted) */
function countedOrders(): array
{
    $ids = array_flip(demoCustomerIds());

    $counted = [];
    foreach (DB::table('orders')->where('is_realized', true)->where('is_fully_refunded', false)->whereNull('deleted_at')
        ->selectRaw('customer_id, count(*) as n')->groupBy('customer_id')->get() as $row) {
        $counted[$ids[$row->customer_id]] = (int) $row->n;
    }

    return $counted;
}

/** Direct call, not $this->seed(): the Artisan wrapper swallows exceptions, which would hide real failures. */
function seedDemo(): void
{
    app(DemoDataSeeder::class)->run();
}

beforeEach(fn () => seedDemo());

it('seeds exactly 50 customers split 20 / 15 / 8 / 4 / 3 as the PRD defines', function () {
    $counted = countedOrders();

    expect(DB::table('customers')->count())->toBe(50)
        ->and(count(DemoDataSeeder::GROUPS))->toBe(5);

    foreach (['one_time' => 20, 'repeat' => 15, 'loyal' => 8, 'at_risk' => 4, 'refund' => 3] as $group => $size) {
        expect(count(DemoDataSeeder::GROUPS[$group]))->toBe($size);
    }

    foreach (DemoDataSeeder::GROUPS['one_time'] as $c) {
        expect($counted[$c])->toBe(1);
    }
    foreach (DemoDataSeeder::GROUPS['repeat'] as $c) {
        expect($counted[$c])->toBeBetween(2, 3);
    }
    foreach (DemoDataSeeder::GROUPS['loyal'] as $c) {
        expect($counted[$c])->toBeGreaterThanOrEqual(4);
    }
    foreach (DemoDataSeeder::GROUPS['at_risk'] as $c) {
        expect($counted[$c])->toBeGreaterThanOrEqual(2);
    }
});

it('makes the at-risk group genuinely at risk: last order between the fallback p75 and p90 thresholds', function () {
    $ids = demoCustomerIds();
    $asOf = DemoDataSeeder::asOf();

    foreach (DemoDataSeeder::GROUPS['at_risk'] as $c) {
        $last = DB::table('orders')->where('customer_id', $ids[$c])->where('is_realized', true)->where('is_fully_refunded', false)
            ->whereNull('deleted_at')->max('ordered_at');
        $recency = (int) abs($asOf->diffInDays(Carbon::parse($last)));

        expect($recency)->toBeGreaterThan(120)->toBeLessThanOrEqual(210);
    }
});

it('has exactly three customers with refunds, covering a partial refund, an amount-only refund and a full refund', function () {
    $withRefunds = DB::table('orders')->join('refunds', 'refunds.order_id', '=', 'orders.id')->distinct()->pluck('orders.customer_id');
    $ids = demoCustomerIds();

    expect($withRefunds)->toHaveCount(3)
        ->and($withRefunds->sort()->values()->all())->toBe(collect(DemoDataSeeder::GROUPS['refund'])->map(fn ($c) => $ids[$c])->sort()->values()->all())
        ->and(DB::table('refunds')->where('is_full', true)->count())->toBe(1)
        ->and(DB::table('refunds')->where('is_full', false)->count())->toBe(2)
        ->and(DB::table('order_items')->where('refunded_qty', 0)->where('refunded_amount', '>', 0)->exists())->toBeTrue()
        ->and(DB::table('order_items')->where('refunded_qty', '>', 0)->exists())->toBeTrue();
});

it('exercises every order-filter branch: unrealized statuses, a soft-deleted order, a fully refunded order', function () {
    $statuses = DB::table('orders')->distinct()->pluck('status')->all();

    foreach (['cancelled', 'pending', 'on-hold', 'failed'] as $status) {
        expect($statuses)->toContain($status);
    }

    expect(DB::table('orders')->whereNotNull('deleted_at')->count())->toBe(1)
        ->and(DB::table('orders')->where('is_fully_refunded', true)->count())->toBe(1)
        ->and(DB::table('orders')->where('is_realized', false)->count())->toBeGreaterThanOrEqual(4);
});

it('derives is_realized from config(woo.realized_statuses), never from a hardcoded list', function () {
    $realized = config('woo.realized_statuses');

    expect($realized)->toBe(['processing', 'completed']);

    foreach (DB::table('orders')->get(['status', 'is_realized']) as $order) {
        expect($order->is_realized)->toBe(in_array($order->status, $realized, true));
    }
});

it('keeps every order internally consistent (money, refunds, items, status history)', function () {
    foreach (DB::table('orders')->get() as $order) {
        $items = DB::table('order_items')->where('order_id', $order->id)->get();
        $refunds = (int) DB::table('refunds')->where('order_id', $order->id)->sum('amount');

        expect($items)->not->toBeEmpty()
            ->and((int) $items->sum('line_subtotal'))->toBe($order->subtotal)
            ->and($order->subtotal - $order->discount_total + $order->shipping_total + $order->tax_total)->toBe($order->total)
            ->and($order->refunded_total)->toBe($refunds)
            ->and($order->net_revenue)->toBe($order->total - $order->refunded_total)
            ->and($order->is_fully_refunded)->toBe($order->refunded_total === $order->total && $order->total > 0);

        foreach ($items as $item) {
            expect($item->line_subtotal)->toBe($item->unit_price * $item->qty)
                ->and($item->refunded_amount)->toBeLessThanOrEqual($item->line_total)
                ->and($item->refunded_qty)->toBeLessThanOrEqual($item->qty);
        }

        $last = DB::table('order_status_history')->where('order_id', $order->id)->orderByDesc('changed_at')->orderByDesc('id')->first();
        expect($last->to_status)->toBe($order->status);
    }
});

it('stores an unresolvable order item with NULL catalog ids but its sku and name (never reject an order)', function () {
    $item = DB::table('order_items')->whereNull('product_id')->first();

    expect($item)->not->toBeNull()->and($item->variation_id)->toBeNull()->and($item->sku)->not->toBeNull()->and($item->name_snapshot)->not->toBeEmpty();
});

it('uses only valid, normalized phones and links every relation the schema defines', function () {
    foreach (DB::table('customers')->pluck('phone_normalized') as $phone) {
        expect(PhoneNormalizer::normalize($phone))->toBe($phone);
    }

    expect(DB::table('customer_identities')->count())->toBe(50)
        ->and(DB::table('customer_addresses')->where('type', 'billing')->count())->toBe(50)
        ->and(DB::table('customer_addresses')->where('type', 'shipping')->count())->toBe(50)
        ->and(DB::table('identity_conflicts')->where('status', 'pending')->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('customer_notes')->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('products')->count())->toBe(12)
        ->and(DB::table('product_variations')->distinct()->count('sku'))->toBe(DB::table('product_variations')->count())
        ->and(DB::table('order_items')->whereNotNull('variation_id')->count())->toBeGreaterThan(0);
});

it('leaves every derived and sync table empty — demo data is not production data', function () {
    foreach (['customer_metrics', 'metric_runs', 'segments', 'segment_members', 'daily_metrics', 'cohort_snapshots', 'product_affinities',
        'customer_category_purchases', 'customer_product_purchases', 'sync_cursors', 'sync_jobs', 'sync_logs', 'reconciliation_reports',
        'integrations', 'ai_insights', 'ai_usage_daily', 'ai_tool_calls', 'product_costs'] as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} must stay empty");
    }

    expect(DB::table('customers')->where('metrics_dirty', true)->count())->toBe(50);
});

it('is deterministic: two runs from an identical empty state produce identical data, ids included', function () {
    // Sequences survive rolled-back tests, so both runs start from RESTART IDENTITY to be comparable.
    DemoSnapshot::truncateDemoTables();
    seedDemo();
    $first = DemoSnapshot::take();

    DemoSnapshot::truncateDemoTables();
    seedDemo();

    expect(DemoSnapshot::take())->toBe($first)->and($first['customers'])->not->toBe('empty');
});

it('is idempotent: seeding again on an already-seeded database changes nothing', function () {
    $first = DemoSnapshot::take();

    seedDemo();
    seedDemo();

    expect(DemoSnapshot::take())->toBe($first)->and(DB::table('customers')->count())->toBe(50);
});

it('contains no source of nondeterminism or network access', function () {
    $code = (string) file_get_contents(base_path('database/seeders/DemoDataSeeder.php'));
    $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $code);

    foreach (['now(', 'Carbon::now', 'today(', 'rand(', 'mt_rand', 'random_int', 'shuffle(', 'fake(', 'faker', 'Str::random', 'Str::uuid',
        'uniqid', 'Http::', 'WooClient', 'Guzzle', 'curl_', 'file_get_contents(\'http'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("seeder must not use {$forbidden}");
    }
});

it('refuses to run in production so demo data can never mix with real synced data', function () {
    DemoSnapshot::truncateDemoTables();
    $this->app->detectEnvironment(fn () => 'production');

    expect(fn () => seedDemo())->toThrow(RuntimeException::class);
    expect(DB::table('customers')->count())->toBe(0);
});

it('is wired into the default seeder in non-production environments', function () {
    DemoSnapshot::truncateDemoTables();

    $this->seed(DatabaseSeeder::class);

    expect(DB::table('customers')->count())->toBe(50)->and(DB::table('roles')->count())->toBe(5);
});

it('lets `php artisan db:seed` be re-run on an already seeded database without failing or duplicating anything', function () {
    $this->artisan('db:seed')->assertExitCode(0);
    $before = DemoSnapshot::take();

    $this->artisan('db:seed')->assertExitCode(0);

    expect(DemoSnapshot::take())->toBe($before)
        ->and(DB::table('users')->where('email', 'test@example.com')->count())->toBe(1)
        ->and(DB::table('roles')->count())->toBe(5);
});
