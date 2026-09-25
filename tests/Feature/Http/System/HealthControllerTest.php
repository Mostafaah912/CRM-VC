<?php

declare(strict_types=1);

use App\Modules\Core\Models\Permission;
use App\Modules\Sync\Enums\ReconciliationStatus;
use App\Modules\Sync\Enums\SyncStatus;
use App\Modules\Sync\Jobs\SyncPageJob;
use App\Modules\Sync\Support\ReconciliationMonths;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P2-12 — GET /system/health. Read-only: last good sync run, queue depth, failed jobs, the last three reconciled months and the
| GATE 1 panel. Behind auth + system.view. It shows what the services already stored (error text is scrubbed at write time) and
| never a cursor, a credential, a payload or a stack trace. `travelTo` fixes "today" at 1405/06/29 Tehran, so the gate covers the
| 23 months 1403-07 .. 1405-05.
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
    $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00', 'UTC'));
});

function healthProps($test, array $keys = ['system.view']): array
{
    return $test->actingAs(Fx::userWith(...$keys))->get('/system/health')->assertOk()->inertiaProps();
}

// ================================================================== access

it('redirects a guest to login', function () {
    $this->get('/system/health')->assertRedirect(route('login'));
});

it('forbids a signed-in user without system.view — including one who only has identity.review', function () {
    $this->actingAs(Fx::userWith())->get('/system/health')->assertForbidden();
    $this->actingAs(Fx::userWith('identity.review'))->get('/system/health')->assertForbidden();
});

it('renders the health component for a user with system.view', function () {
    $this->actingAs(Fx::userWith('system.view'))
        ->get('/system/health')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('system/health')
            ->has('last_sync')
            ->has('queue')
            ->has('reconciliation')
            ->has('gate_one'),
        );
});

it('lets an explicit deny override beat the role grant', function () {
    $user = Fx::userWith('system.view');
    $permission = Permission::query()->where('module', 'system')->where('action', 'view')->firstOrFail();
    $user->permissionOverrides()->create(['permission_id' => $permission->id, 'effect' => 'deny']);

    $this->actingAs($user)->get('/system/health')->assertForbidden();
});

// ================================================================== shape and safety

it('sends exactly the documented props, and no cursor, credential, id, payload or trace key anywhere', function () {
    Fx::run(SyncStatus::Completed, CarbonImmutable::parse('2026-09-20 09:00:00', 'UTC'), ['error' => 'old leftover']);
    Fx::report('1405-05', ReconciliationStatus::Failed, error: 'Woo timed out');
    Fx::report('1405-04', ReconciliationStatus::Green);
    DB::table('failed_jobs')->insert(['uuid' => 'u-1', 'connection' => 'redis', 'queue' => 'sync', 'payload' => '{"secret":"SECRET-PAYLOAD"}', 'exception' => 'SECRET-TRACE at /var/www/app', 'failed_at' => now()]);

    $props = healthProps($this);
    $page = Fx::pageProps($props);

    expect(array_keys($page))->toEqualCanonicalizing(['last_sync', 'queue', 'reconciliation', 'gate_one'])
        ->and(array_keys($page['last_sync']))->toEqualCanonicalizing(['started_at', 'finished_at', 'duration_seconds', 'pages_processed', 'records_processed'])
        ->and(array_keys($page['queue']))->toEqualCanonicalizing(['sync_depth', 'failed_jobs'])
        ->and(array_keys($page['reconciliation'][0]))->toEqualCanonicalizing(['month', 'status', 'count_diff', 'diff_percent', 'error'])
        ->and(array_keys($page['gate_one']))->toEqualCanonicalizing(['passed', 'total_months', 'green_months', 'missing_months', 'failing_months'])
        ->and(array_intersect(Fx::keysDeep($page), Fx::FORBIDDEN_KEYS))->toBe([])
        ->and(json_encode($page))->not->toContain('SECRET-PAYLOAD')->not->toContain('SECRET-TRACE')->not->toContain('old leftover');
});

it('renders when WooCommerce is not configured — the health page is needed most exactly then', function () {
    config(['woo.base_url' => '', 'woo.key' => '', 'woo.secret' => '']);

    $props = healthProps($this);

    expect($props['gate_one']['passed'])->toBeFalse();
});

// ================================================================== last successful sync

it('shows no last sync when nothing has completed', function () {
    Fx::run(SyncStatus::Failed, CarbonImmutable::parse('2026-09-20 09:00:00', 'UTC'), ['error' => 'boom']);
    Fx::run(SyncStatus::Running, CarbonImmutable::parse('2026-09-20 09:30:00', 'UTC'));

    expect(healthProps($this)['last_sync'])->toBeNull();
});

it('shows the most recent completed orders run — in Jalali, Tehran time, with its counters and computed duration', function () {
    Fx::run(SyncStatus::Completed, CarbonImmutable::parse('2026-03-19 08:00:00', 'UTC'), ['pages_processed' => 9, 'records_processed' => 90]);
    // 2026-03-20 20:30 UTC = 1405/01/01 00:00 in Tehran (UTC+3:30, no DST)
    Fx::run(SyncStatus::Completed, CarbonImmutable::parse('2026-03-20 20:30:00', 'UTC'), ['pages_processed' => 5, 'records_processed' => 123]);
    Fx::run(SyncStatus::Failed, CarbonImmutable::parse('2026-09-20 09:00:00', 'UTC'), ['error' => 'boom']);

    expect(healthProps($this)['last_sync'])->toBe([
        'started_at' => '1405/01/01 00:00:00',
        'finished_at' => '1405/01/01 00:00:42',
        'duration_seconds' => 42,
        'pages_processed' => 5,
        'records_processed' => 123,
    ]);
});

// ================================================================== queue

it('reports the depth of the sync queue only, and the failed-jobs count', function () {
    Queue::fake();
    Queue::push(new SyncPageJob(1, 1), '', 'sync');
    Queue::push(new SyncPageJob(1, 2), '', 'sync');
    Queue::push(new SyncPageJob(1, 3), '', 'critical');
    foreach (['a', 'b', 'c'] as $uuid) {
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'redis', 'queue' => 'sync', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
    }

    expect(healthProps($this)['queue'])->toBe(['sync_depth' => 2, 'failed_jobs' => 3]);
});

it('shows zero when the queue is empty and nothing failed', function () {
    expect(healthProps($this)['queue'])->toBe(['sync_depth' => 0, 'failed_jobs' => 0]);
});

it('shows the queue depth as unknown — not an error page — when the queue backend is unreachable, without leaking why', function () {
    Queue::shouldReceive('size')->with('sync')->andThrow(new RuntimeException('Connection refused redis://:hunter2@10.0.0.5:6379'));

    $response = $this->actingAs(Fx::userWith('system.view'))->get('/system/health')->assertOk();

    expect($response->inertiaProps()['queue']['sync_depth'])->toBeNull()
        ->and($response->getContent())->not->toContain('hunter2')->not->toContain('10.0.0.5');
});

// ================================================================== reconciliation summary

it('lists the three most recent reconciled months, newest first, with status, count difference and percent', function () {
    Fx::report('1405-02', ReconciliationStatus::Green);
    Fx::report('1405-03', ReconciliationStatus::Green);
    Fx::report('1405-04', ReconciliationStatus::Red, countDiff: 2, percent: '1.2500');
    Fx::report('1405-05', ReconciliationStatus::Green);

    expect(healthProps($this)['reconciliation'])->toBe([
        ['month' => '1405-05', 'status' => 'green', 'count_diff' => 0, 'diff_percent' => '0.0000', 'error' => null],
        ['month' => '1405-04', 'status' => 'red', 'count_diff' => 2, 'diff_percent' => '1.2500', 'error' => null],
        ['month' => '1405-03', 'status' => 'green', 'count_diff' => 0, 'diff_percent' => '0.0000', 'error' => null],
    ]);
});

it('shows a failed month with its stored, already-scrubbed reason and no measurements', function () {
    Fx::report('1405-05', ReconciliationStatus::Failed, error: 'Woo returned HTTP 503 for orders page 4.');

    expect(healthProps($this)['reconciliation'])->toBe([
        ['month' => '1405-05', 'status' => 'failed', 'count_diff' => null, 'diff_percent' => null, 'error' => 'Woo returned HTTP 503 for orders page 4.'],
    ]);
});

it('shows an empty list before any month was reconciled', function () {
    expect(healthProps($this)['reconciliation'])->toBe([]);
});

// ================================================================== GATE 1 panel

it('shows GATE 1 open with every month missing when nothing was reconciled — the expected state today', function () {
    $months = (new ReconciliationMonths)->all();

    $gate = healthProps($this)['gate_one'];

    expect($gate['passed'])->toBeFalse()
        ->and($gate['total_months'])->toBe(23)->toBe(count($months))
        ->and($gate['green_months'])->toBe(0)
        ->and($gate['missing_months'])->toBe($months)
        ->and($gate['missing_months'][0])->toBe('1403-07')
        ->and($gate['missing_months'][22])->toBe('1405-05')
        ->and($gate['failing_months'])->toBe([]);
});

it('lists red and failed months as failing, with their figures, and leaves them out of the missing list', function () {
    Fx::report('1405-04', ReconciliationStatus::Red, countDiff: 3, percent: '2.5000');
    Fx::report('1405-05', ReconciliationStatus::Failed, error: 'x');
    Fx::report('1403-07', ReconciliationStatus::Green);

    $gate = healthProps($this)['gate_one'];

    expect($gate['passed'])->toBeFalse()
        ->and($gate['green_months'])->toBe(1)
        ->and($gate['failing_months'])->toBe([
            ['month' => '1405-04', 'status' => 'red', 'count_diff' => 3, 'diff_percent' => '2.5000'],
            ['month' => '1405-05', 'status' => 'failed', 'count_diff' => null, 'diff_percent' => null],
        ])
        ->and($gate['missing_months'])->not->toContain('1405-04')->not->toContain('1405-05')->not->toContain('1403-07')
        ->and($gate['missing_months'])->toHaveCount(20);
});

it('shows GATE 1 passed only when every month from 1403-07 to the last complete month is green', function () {
    $months = (new ReconciliationMonths)->all();

    foreach ($months as $month) {
        Fx::report($month, ReconciliationStatus::Green);
    }

    $gate = healthProps($this)['gate_one'];

    expect($gate)->toBe(['passed' => true, 'total_months' => 23, 'green_months' => 23, 'missing_months' => [], 'failing_months' => []]);
});

it('keeps GATE 1 open when one month of an otherwise green history is missing', function () {
    foreach (array_slice((new ReconciliationMonths)->all(), 1) as $month) {
        Fx::report($month, ReconciliationStatus::Green);
    }

    $gate = healthProps($this)['gate_one'];

    expect($gate['passed'])->toBeFalse()->and($gate['missing_months'])->toBe(['1403-07']);
});

it('does not mutate anything: a page view writes no row', function () {
    $before = [DB::table('sync_jobs')->count(), DB::table('reconciliation_reports')->count(), DB::table('sync_cursors')->count(), DB::table('audit_logs')->count()];

    healthProps($this);

    expect([DB::table('sync_jobs')->count(), DB::table('reconciliation_reports')->count(), DB::table('sync_cursors')->count(), DB::table('audit_logs')->count()])->toBe($before);
});
