<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Segments\Exceptions\SegmentException;
use App\Modules\Segments\Models\Segment;
use App\Modules\Segments\Services\SegmentService;
use Illuminate\Support\Facades\DB;

/*
| PRD §17: "preview: compile->count() with statement_timeout = 5s". A rule too expensive to run is
| cancelled server-side and surfaces as a clear Persian SegmentException, never a bare 500 or a hung
| request. The timeout test below bulk-inserts 20,000 real rows (raw SQL, ~1s — Eloquent factories
| are too slow for this) so a real, unindexed ILIKE scan genuinely exceeds a 1ms statement_timeout —
| no pg_sleep trickery, the exact query path production would run. The core SET LOCAL/reset
| mechanism itself is proven deterministically (real pg_sleep) in PostgresStatementTimeoutTest.php.
*/

afterEach(function () {
    config(['segments.preview_timeout_ms' => 5000]);
});

it('returns the count of matching customers', function () {
    $customer = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $customer->id, 'total_orders' => 5]);

    $segment = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 3]]);

    expect(app(SegmentService::class)->preview($segment))->toBe(1);
});

it('does not write to segment_members', function () {
    $customer = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $customer->id, 'total_orders' => 5]);

    $segment = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 3]]);

    app(SegmentService::class)->preview($segment);

    expect(DB::table('segment_members')->where('segment_id', $segment->id)->count())->toBe(0);
});

it('throws a Persian SegmentException when the statement_timeout cancels a genuinely expensive query', function () {
    DB::statement("
        INSERT INTO customers (phone_normalized, display_name, city, status, lifecycle_stage, metrics_dirty, needs_review, created_at, updated_at)
        SELECT '98900'||lpad(gs::text, 7, '0'), 'کاربر '||gs, 'شهر '||(gs % 50), 'active', 'prospect', true, false, now(), now()
        FROM generate_series(1, 20000) gs
    ");
    DB::statement('INSERT INTO customer_metrics (customer_id, total_orders, computed_at) SELECT id, 0, now() FROM customers');
    config(['segments.preview_timeout_ms' => 1]);

    $segment = Segment::factory()->create(['rule' => ['field' => 'city', 'operator' => 'contains', 'value' => 'شهر']]);

    try {
        app(SegmentService::class)->preview($segment);
        expect(false)->toBeTrue('Expected SegmentException, none thrown.');
    } catch (SegmentException $e) {
        expect($e->reason)->toBe(SegmentException::PREVIEW_TIMED_OUT)
            ->and($e->getMessage())->toMatch('/\p{Arabic}/u');
    }
});

// "Reset after timeout" is deliberately NOT re-proven here with a second real preview() call: an
// earlier version chained a second 20,000-row scan after the timed-out one and it flaked twice
// across full-suite runs (~1 in several hundred) purely on host timing — a 5000ms budget against a
// ~35ms query is a huge margin, but "huge margin, still occasionally flaky" is exactly what happens
// under shared system load, and it added no real coverage beyond what the mechanism test already
// proves. PostgresStatementTimeoutTest.php proves the reset deterministically with pg_sleep(1) vs a
// 50ms cutoff — no ambient timing dependency, never flaked. See ARCHITECTURE.md, P5-05.
