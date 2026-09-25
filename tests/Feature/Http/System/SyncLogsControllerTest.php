<?php

declare(strict_types=1);

use App\Modules\Sync\Enums\SyncStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P2-12 — GET /system/sync-logs. A read-only, newest-first, 25-per-page list of sync runs with a status filter and an entity
| filter. A row carries what an operator needs and nothing else: no cursor, no id, and the stored error only for a FAILED run
| (it was scrubbed when SyncService wrote it, so it is passed through untouched).
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
    $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00', 'UTC'));
});

function syncLogsProps($test, string $query = ''): array
{
    return $test->actingAs(Fx::userWith('system.view'))->get('/system/sync-logs'.$query)->assertOk()->inertiaProps();
}

/** Runs one minute apart, oldest first, so "newest first" and paging are unambiguous. */
function seedRuns(int $count, SyncStatus $status = SyncStatus::Completed): void
{
    $start = CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC');

    for ($i = 0; $i < $count; $i++) {
        Fx::run($status, $start->addMinutes($i), ['records_processed' => $i]);
    }
}

// ================================================================== access

it('redirects a guest to login', function () {
    $this->get('/system/sync-logs')->assertRedirect(route('login'));
});

it('forbids a signed-in user without system.view', function () {
    $this->actingAs(Fx::userWith())->get('/system/sync-logs')->assertForbidden();
    $this->actingAs(Fx::userWith('identity.review', 'audit.view'))->get('/system/sync-logs')->assertForbidden();
});

it('renders the sync-logs component with the runs, the current filters and the filter options', function () {
    $this->actingAs(Fx::userWith('system.view'))
        ->get('/system/sync-logs')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('system/sync-logs')
            ->has('runs.data')
            ->where('filters', ['status' => null, 'entity' => null])
            ->where('options', ['statuses' => ['running', 'completed', 'failed', 'partial'], 'entities' => ['orders']]),
        );
});

// ================================================================== shape and safety

it('sends only the documented columns per run, and no cursor, id, mode or credential key anywhere', function () {
    Fx::run(SyncStatus::Completed, CarbonImmutable::parse('2026-09-20 09:00:00', 'UTC'));

    $page = Fx::pageProps(syncLogsProps($this));

    expect(array_keys($page['runs']['data'][0]))->toEqualCanonicalizing(['started_at', 'entity', 'status', 'pages_processed', 'records_processed', 'finished_at', 'duration_seconds', 'error'])
        ->and(array_intersect(Fx::keysDeep($page), [...Fx::FORBIDDEN_KEYS, 'mode', 'records_failed']))->toBe([])
        ->and(json_encode($page))->not->toContain('2026-09-20T08:45');
});

it('formats a run in Jalali and Tehran time and computes its duration in seconds', function () {
    // 2026-03-20 20:30 UTC = 1405/01/01 00:00 in Tehran
    Fx::run(SyncStatus::Completed, CarbonImmutable::parse('2026-03-20 20:30:00', 'UTC'), ['pages_processed' => 7, 'records_processed' => 321]);

    expect(syncLogsProps($this)['runs']['data'][0])->toBe([
        'started_at' => '1405/01/01 00:00:00',
        'entity' => 'orders',
        'status' => 'completed',
        'pages_processed' => 7,
        'records_processed' => 321,
        'finished_at' => '1405/01/01 00:00:42',
        'duration_seconds' => 42,
        'error' => null,
    ]);
});

it('shows a running run with no finish time and no duration', function () {
    Fx::run(SyncStatus::Running, CarbonImmutable::parse('2026-09-20 09:00:00', 'UTC'));

    $row = syncLogsProps($this)['runs']['data'][0];

    expect($row['status'])->toBe('running')->and($row['finished_at'])->toBeNull()->and($row['duration_seconds'])->toBeNull();
});

it('passes the stored error through untouched for a failed run', function () {
    Fx::run(SyncStatus::Failed, CarbonImmutable::parse('2026-09-20 09:00:00', 'UTC'), ['error' => 'RuntimeException: Woo returned HTTP 503 for orders page 4.']);

    $row = syncLogsProps($this)['runs']['data'][0];

    expect($row['status'])->toBe('failed')->and($row['error'])->toBe('RuntimeException: Woo returned HTTP 503 for orders page 4.');
});

it('shows an error only for failed runs, even when another status has text in its error column', function (SyncStatus $status) {
    Fx::run($status, CarbonImmutable::parse('2026-09-20 09:00:00', 'UTC'), ['error' => 'left over text']);

    $body = syncLogsProps($this);

    expect($body['runs']['data'][0]['error'])->toBeNull()->and(json_encode($body))->not->toContain('left over text');
})->with([SyncStatus::Running, SyncStatus::Completed, SyncStatus::Partial]);

it('does not crash on a run whose entity this build does not know — it shows the stored name', function () {
    Fx::run(SyncStatus::Completed, CarbonImmutable::parse('2026-09-20 09:00:00', 'UTC'));
    DB::table('sync_jobs')->insert(['entity' => 'products', 'mode' => 'full', 'status' => 'completed', 'started_at' => '2026-09-20 09:30:00+00', 'finished_at' => '2026-09-20 09:31:00+00', 'cursor_to' => '2026-09-20 09:30:00+00']);

    $rows = syncLogsProps($this)['runs']['data'];

    expect(array_column($rows, 'entity'))->toBe(['products', 'orders']);
});

// ================================================================== order and paging

it('lists runs newest first', function () {
    seedRuns(3);

    expect(array_column(syncLogsProps($this)['runs']['data'], 'records_processed'))->toBe([2, 1, 0]);
});

it('pages 25 runs at a time — page 1 is the newest 25 and page 2 the rest', function () {
    seedRuns(30);

    $first = syncLogsProps($this)['runs'];
    $second = syncLogsProps($this, '?page=2')['runs'];

    expect($first['data'])->toHaveCount(25)
        ->and($first['total'])->toBe(30)
        ->and($first['current_page'])->toBe(1)
        ->and($first['last_page'])->toBe(2)
        ->and($first['data'][0]['records_processed'])->toBe(29)
        ->and($second['data'])->toHaveCount(5)
        ->and($second['current_page'])->toBe(2)
        ->and(array_column($second['data'], 'records_processed'))->toBe([4, 3, 2, 1, 0]);
});

it('shows an empty page for a page beyond the last, not an error', function () {
    seedRuns(3);

    expect(syncLogsProps($this, '?page=9')['runs']['data'])->toBe([]);
});

// ================================================================== filters

it('filters by status', function (string $status, int $expected) {
    Fx::run(SyncStatus::Completed, CarbonImmutable::parse('2026-09-20 01:00:00', 'UTC'));
    Fx::run(SyncStatus::Completed, CarbonImmutable::parse('2026-09-20 02:00:00', 'UTC'));
    Fx::run(SyncStatus::Failed, CarbonImmutable::parse('2026-09-20 03:00:00', 'UTC'), ['error' => 'boom']);
    Fx::run(SyncStatus::Running, CarbonImmutable::parse('2026-09-20 04:00:00', 'UTC'));

    $runs = syncLogsProps($this, "?status={$status}")['runs'];

    expect($runs['data'])->toHaveCount($expected)
        ->and(array_unique(array_column($runs['data'], 'status')))->toBe([$status]);
})->with([['completed', 2], ['failed', 1], ['running', 1]]);

it('filters by entity, and shows every entity when none is chosen', function () {
    Fx::run(SyncStatus::Completed, CarbonImmutable::parse('2026-09-20 01:00:00', 'UTC'));
    DB::table('sync_jobs')->insert(['entity' => 'products', 'mode' => 'full', 'status' => 'completed', 'started_at' => '2026-09-20 02:00:00+00', 'cursor_to' => '2026-09-20 02:00:00+00']);

    expect(array_column(syncLogsProps($this, '?entity=orders')['runs']['data'], 'entity'))->toBe(['orders'])
        ->and(array_column(syncLogsProps($this)['runs']['data'], 'entity'))->toBe(['products', 'orders']);
});

it('combines the status and entity filters', function () {
    Fx::run(SyncStatus::Completed, CarbonImmutable::parse('2026-09-20 01:00:00', 'UTC'));
    Fx::run(SyncStatus::Failed, CarbonImmutable::parse('2026-09-20 02:00:00', 'UTC'), ['error' => 'boom']);

    $runs = syncLogsProps($this, '?status=failed&entity=orders')['runs']['data'];

    expect($runs)->toHaveCount(1)->and($runs[0]['status'])->toBe('failed');
});

it('echoes the active filters back, and carries them into the paging links', function () {
    seedRuns(30);

    $props = syncLogsProps($this, '?status=completed&entity=orders');

    expect($props['filters'])->toBe(['status' => 'completed', 'entity' => 'orders'])
        ->and($props['runs']['next_page_url'])->toContain('status=completed')->toContain('entity=orders')->toContain('page=2');
});

it('pages within a filter', function () {
    seedRuns(30);
    Fx::run(SyncStatus::Failed, CarbonImmutable::parse('2026-09-19 00:00:00', 'UTC'), ['error' => 'boom']);

    $page2 = syncLogsProps($this, '?status=completed&page=2')['runs'];

    expect($page2['total'])->toBe(30)->and($page2['data'])->toHaveCount(5);
});

it('treats an empty filter value as "all"', function () {
    seedRuns(2);

    expect(syncLogsProps($this, '?status=&entity=')['runs']['data'])->toHaveCount(2);
});

it('rejects an unknown status or entity instead of ignoring it — and never runs the query with it', function (string $query) {
    seedRuns(2);

    $this->actingAs(Fx::userWith('system.view'))->get('/system/sync-logs'.$query)->assertRedirect()->assertSessionHasErrors();
})->with(['?status=exploded', '?entity=customers', '?status[]=failed', "?status=failed'%20OR%201=1"]);

it('does not mutate anything: a page view writes no row', function () {
    seedRuns(3);
    $before = [DB::table('sync_jobs')->count(), DB::table('sync_cursors')->count(), DB::table('sync_logs')->count()];

    syncLogsProps($this);

    expect([DB::table('sync_jobs')->count(), DB::table('sync_cursors')->count(), DB::table('sync_logs')->count()])->toBe($before);
});
