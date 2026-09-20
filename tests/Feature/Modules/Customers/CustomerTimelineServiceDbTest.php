<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerTimelineService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
| P3-04 — CustomerTimelineService against real rows: newest first with the id as tie-break, cursor paging that never repeats or
| skips, an exact has_more, the customer scope, the soft-delete 404, and the query budget (1 for page(), 2 for pageFor()).
| The cursor codec and the payload allowlist are pure and tested in tests/Unit.
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
    $this->customer = Customer::factory()->create();
});

/** @return int the new event's id */
function cteEvent(int $customerId, string $at, string $type = 'note_added', ?array $payload = null): int
{
    return (int) DB::table('customer_events')->insertGetId([
        'customer_id' => $customerId,
        'event_type' => $type,
        'payload' => $payload === null ? null : json_encode($payload),
        'happened_at' => $at,
    ]);
}

/** @return list<int> */
function cteIds(array $page): array
{
    return array_column($page['data'], 'id');
}

function cteService(): CustomerTimelineService
{
    return app(CustomerTimelineService::class);
}

/** Walk every page with $perPage, returning the pages. */
function cteWalk(int $customerId, int $perPage): array
{
    $pages = [];
    $cursor = null;

    do {
        $page = cteService()->pageFor($customerId, $cursor, $perPage)->toArray();
        $pages[] = $page;
        $cursor = $page['next_cursor'];
    } while ($cursor !== null && count($pages) < 50);

    return $pages;
}

// ================================================================== order

it('lists events newest first by happened_at — not by id, which may be older than the event', function () {
    $old = cteEvent($this->customer->id, '2026-03-01 10:00:00+00');
    $newest = cteEvent($this->customer->id, '2026-03-09 10:00:00+00');
    $backfilled = cteEvent($this->customer->id, '2026-03-05 10:00:00+00'); // highest id, middle date

    expect(cteIds(cteService()->pageFor($this->customer->id)->toArray()))->toBe([$newest, $backfilled, $old]);
});

it('breaks a tie between events of the same instant by id, newest id first', function () {
    $first = cteEvent($this->customer->id, '2026-03-01 10:00:00+00');
    $second = cteEvent($this->customer->id, '2026-03-01 10:00:00+00');
    $third = cteEvent($this->customer->id, '2026-03-01 10:00:00+00');

    expect(cteIds(cteService()->pageFor($this->customer->id)->toArray()))->toBe([$third, $second, $first]);
});

// ================================================================== paging

it('walks 45 events in pages of 20, 20 and 5 — every event once, in order, with has_more true, true, false', function () {
    $expected = [];

    foreach (range(1, 45) as $i) {
        $expected[] = cteEvent($this->customer->id, '2026-03-01 00:00:00+00', payload: ['old_status' => "s{$i}"]);
    }
    // Spread over time as well, so both keys of the position matter.
    DB::table('customer_events')->where('customer_id', $this->customer->id)->where('id', '<=', $expected[14])->update(['happened_at' => '2026-02-01 00:00:00+00']);
    $everything = DB::table('customer_events')->where('customer_id', $this->customer->id)->orderByDesc('happened_at')->orderByDesc('id')->pluck('id')->all();

    $pages = cteWalk($this->customer->id, 20);

    expect(array_map(fn (array $p) => count($p['data']), $pages))->toBe([20, 20, 5])
        ->and(array_column($pages, 'has_more'))->toBe([true, true, false])
        ->and(array_merge(...array_map('cteIds', $pages)))->toBe($everything)
        ->and($pages[2]['next_cursor'])->toBeNull()
        ->and($pages[0]['next_cursor'])->toBeString();
});

it('has no next page when a page is exactly full and nothing follows — has_more is exact, not a guess', function () {
    foreach (range(1, 20) as $i) {
        cteEvent($this->customer->id, '2026-03-01 00:00:00+00');
    }

    $page = cteService()->pageFor($this->customer->id, null, 20)->toArray();

    expect($page['data'])->toHaveCount(20)->and($page['has_more'])->toBeFalse()->and($page['next_cursor'])->toBeNull();
});

it('has a next page as soon as one more event exists', function () {
    foreach (range(1, 21) as $i) {
        cteEvent($this->customer->id, '2026-03-01 00:00:00+00');
    }

    $page = cteService()->pageFor($this->customer->id, null, 20)->toArray();

    expect($page['data'])->toHaveCount(20)->and($page['has_more'])->toBeTrue()->and($page['next_cursor'])->toBeString();
});

it('pages across a tie: five events of one instant, two at a time, are all shown once', function () {
    $ids = array_map(fn () => cteEvent($this->customer->id, '2026-03-01 10:00:00+00'), range(1, 5));

    $pages = cteWalk($this->customer->id, 2);

    expect(array_merge(...array_map('cteIds', $pages)))->toBe(array_reverse($ids))
        ->and(array_map(fn (array $p) => count($p['data']), $pages))->toBe([2, 2, 1]);
});

it('does not repeat or skip an event when a newer one arrives between two pages — the cursor is a position, not an offset', function () {
    foreach (range(1, 6) as $day) {
        cteEvent($this->customer->id, "2026-03-0{$day} 10:00:00+00");
    }
    $first = cteService()->pageFor($this->customer->id, null, 3)->toArray();

    cteEvent($this->customer->id, '2026-03-20 10:00:00+00'); // newer than everything, arrives now
    $second = cteService()->pageFor($this->customer->id, $first['next_cursor'], 3)->toArray();

    $all = DB::table('customer_events')->where('happened_at', '<', '2026-03-20')->orderByDesc('happened_at')->pluck('id')->all();

    expect(array_merge(cteIds($first), cteIds($second)))->toBe($all)
        ->and($second['has_more'])->toBeFalse();
});

it('gives an empty page — no next cursor — for a customer with no events, and for a cursor past the oldest', function () {
    $empty = cteService()->pageFor($this->customer->id)->toArray();
    $only = cteEvent($this->customer->id, '2026-03-01 10:00:00+00');
    $cursor = cteService()->encodeCursor(new CarbonImmutable('2026-03-01 10:00:00', new DateTimeZone('UTC')), $only);
    $past = cteService()->pageFor($this->customer->id, $cursor)->toArray();

    expect($empty)->toBe(['data' => [], 'next_cursor' => null, 'has_more' => false])
        ->and($past)->toBe(['data' => [], 'next_cursor' => null, 'has_more' => false]);
});

it('takes the page size from the caller, defaults to 20, and clamps to 1..50', function (?int $asked, int $expected) {
    foreach (range(1, 60) as $i) {
        cteEvent($this->customer->id, '2026-03-01 00:00:00+00');
    }

    expect(cteService()->pageFor($this->customer->id, null, $asked)->toArray()['data'])->toHaveCount($expected);
})->with([[null, 20], [1, 1], [7, 7], [50, 50], [51, 50], [1000, 50], [0, 1], [-4, 1]]);

// ================================================================== the cursor

it('refuses a cursor that is not ours as a validation error on "cursor" — before any query', function (string $cursor) {
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    try {
        cteService()->page($this->customer, $cursor);
        $thrown = null;
    } catch (ValidationException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(ValidationException::class)
        ->and(array_keys($thrown->errors()))->toBe(['cursor'])
        ->and($queries)->toBe([]);
})->with(['garbage' => ['not-a-cursor!'], 'wrong shape' => [rtrim(strtr(base64_encode('{"a":1}'), '+/', '-_'), '=')], 'sql' => ["1' OR '1'='1"]]);

it('keeps a cursor inside its own customer: another customer\'s position never reveals that customer\'s events', function () {
    $other = Customer::factory()->create();
    $otherEvent = cteEvent($other->id, '2026-03-05 10:00:00+00');
    $mine = [cteEvent($this->customer->id, '2026-03-02 10:00:00+00'), cteEvent($this->customer->id, '2026-03-04 10:00:00+00')];

    // A cursor minted from the OTHER customer's event, replayed on mine.
    $cursor = cteService()->encodeCursor(new CarbonImmutable('2026-03-05 10:00:00', new DateTimeZone('UTC')), $otherEvent);
    $page = cteService()->pageFor($this->customer->id, $cursor)->toArray();

    expect(cteIds($page))->toBe([$mine[1], $mine[0]])->and(cteIds($page))->not->toContain($otherEvent);
});

it('never lists another customer\'s events', function () {
    $other = Customer::factory()->create();
    cteEvent($other->id, '2026-03-05 10:00:00+00');
    $mine = cteEvent($this->customer->id, '2026-03-01 10:00:00+00');

    expect(cteIds(cteService()->pageFor($this->customer->id)->toArray()))->toBe([$mine]);
});

// ================================================================== soft delete

it('refuses a soft-deleted customer as not found, even though its events still exist', function () {
    cteEvent($this->customer->id, '2026-03-01 10:00:00+00');
    $this->customer->delete();

    cteService()->pageFor($this->customer->id);
})->throws(ModelNotFoundException::class);

it('refuses an id that does not exist as not found', function () {
    cteService()->pageFor(987_654_321);
})->throws(ModelNotFoundException::class);

// ================================================================== shape

it('shapes an event as id, type, Jalali and ISO dates, and the filtered payload — nothing else', function () {
    // 2026-03-20 20:30 UTC = 1405/01/01 00:00 Tehran
    $id = cteEvent($this->customer->id, '2026-03-20 20:30:00+00', 'order_placed', ['woo_order_id' => 12345, 'email' => 'a@b.test', 'phone' => '989121234567']);

    $event = cteService()->pageFor($this->customer->id)->toArray()['data'][0];

    expect($event)->toBe([
        'id' => $id,
        'event_type' => 'order_placed',
        'happened_at_jalali' => '1405/01/01 00:00:00',
        'happened_at_iso' => '2026-03-20T20:30:00Z',
        'payload' => ['woo_order_id' => 12345],
    ])->and(json_encode($event))->not->toContain('a@b.test')->not->toContain('989121234567');
});

it('gives a null payload for an event stored without one, or with only keys that are not allowed', function () {
    cteEvent($this->customer->id, '2026-03-01 10:00:00+00', 'note_added', null);
    cteEvent($this->customer->id, '2026-03-02 10:00:00+00', 'note_added', ['secret' => 'x']);

    expect(array_column(cteService()->pageFor($this->customer->id)->toArray()['data'], 'payload'))->toBe([null, null]);
});

// ================================================================== budget, read-only

it('costs one query when the customer is already loaded, two when it must be found', function () {
    foreach (range(1, 30) as $i) {
        cteEvent($this->customer->id, '2026-03-01 00:00:00+00', 'order_placed', ['woo_order_id' => $i]);
    }
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    cteService()->page($this->customer);
    $afterPage = count($sql);
    cteService()->pageFor($this->customer->id);

    expect($afterPage)->toBe(1)->and(count($sql) - $afterPage)->toBe(2);
});

it('writes nothing: reading a timeline changes no row', function () {
    cteEvent($this->customer->id, '2026-03-01 10:00:00+00');
    $tables = ['customers', 'customer_events', 'audit_logs', 'phone_reveal_logs'];
    $count = fn () => array_map(fn (string $t) => DB::table($t)->count(), $tables);
    $before = $count();

    cteService()->pageFor($this->customer->id);

    expect($count())->toBe($before);
});
