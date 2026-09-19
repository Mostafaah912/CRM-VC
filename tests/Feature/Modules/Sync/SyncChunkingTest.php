<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Enums\SyncLogLevel;
use App\Modules\Sync\Enums\SyncMode;
use App\Modules\Sync\Enums\SyncStatus;
use App\Modules\Sync\Exceptions\SyncStalledException;
use App\Modules\Sync\Jobs\SyncPageJob;
use App\Modules\Sync\Models\SyncCursor;
use App\Modules\Sync\Models\SyncJob;
use App\Modules\Sync\Models\SyncLog;
use App\Modules\Sync\Services\SyncService;
use App\Modules\Sync\Services\WooClient;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WooOrdersSimulator;

/*
| P2-10 — the full sync and the chunking. A FULL run reads from the epoch (Mehr 1, 1403, derived from the Jalali calendar)
| instead of the stored cursor, and — like every run — touches the stored cursor only when it completes. Every run,
| full or incremental, stops after woo.sync_max_pages_per_run pages if Woo has more; it then completes and moves the
| cursor to the last processed order's modified time, so the next run continues from there. A chunk that cannot move the
| next window forward fails instead of looping. Woo is a simulator that honours the window; "now" is pinned.
*/

const CHUNK_NOW = '2026-06-01 12:00:00';

beforeEach(function () {
    config(['logging.default' => 'null', 'woo.sync_max_pages_per_run' => 2]);
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse(CHUNK_NOW, 'UTC'));
});

function chunkService(WooClient $woo): SyncService
{
    app()->instance(WooClient::class, $woo);

    return app(SyncService::class);
}

function chunkIso(?CarbonImmutable $moment): ?string
{
    return $moment?->utc()->format('Y-m-d\TH:i:s');
}

function chunkCursor(?string $value, SyncStatus $status = SyncStatus::Completed): SyncCursor
{
    return SyncCursor::create(['entity' => 'orders', 'cursor_value' => $value, 'last_status' => $status]);
}

function chunkStored(): ?string
{
    return chunkIso(SyncCursor::findOrFail('orders')->cursor_value);
}

/** With the queue faked, a run's chain is driven by hand: page after page until the run stops running. */
function chunkDrive(SyncService $service, SyncJob $run): SyncJob
{
    for ($page = 1; $page <= 50; $page++) {
        $service->runPage($run->id, $page);

        if ($run->refresh()->status !== SyncStatus::Running) {
            break;
        }
    }

    return $run;
}

// ================================================================== the epoch and the limit

it('derives the epoch from the Jalali calendar: Mehr 1, 1403 = 2024-09-22 00:00 Asia/Tehran', function () {
    $epoch = CarbonImmutable::parse((string) config('woo.sync_epoch'));

    expect($epoch->equalTo(JalaliDate::toGregorian(1403, 7, 1)))->toBeTrue()
        ->and(JalaliDate::format($epoch))->toBe('1403/07/01')
        ->and(chunkIso($epoch))->toBe('2024-09-21T20:30:00');
});

it('defaults to 500 pages per run', function () {
    expect((require config_path('woo.php'))['sync_max_pages_per_run'])->toBe(500);
});

// ================================================================== a full run

it('starts a full run from the epoch, ignoring the stored cursor, and leaves the stored cursor alone', function () {
    Queue::fake();
    chunkCursor('2026-06-01 11:30:00+00');

    $run = chunkService(WooOrdersSimulator::every(1))->run(SyncEntity::Orders, SyncMode::Full);

    expect($run->mode)->toBe(SyncMode::Full)
        ->and(chunkIso($run->cursor_from))->toBe('2024-09-21T20:20:00') // the epoch as a cursor: minus the 10-minute overlap
        ->and(chunkIso($run->cursor_to))->toBe('2026-06-01T12:00:00')
        ->and(chunkStored())->toBe('2026-06-01T11:30:00');
    Queue::assertPushed(SyncPageJob::class, fn (SyncPageJob $job) => $job->syncJobId === $run->id && $job->page === 1);
    expect(SyncLog::where('sync_job_id', $run->id)->where('message', 'Run started')->sole()->context['mode'])->toBe('full');
});

it('reads the epoch from config, not from code', function () {
    Queue::fake();
    config(['woo.sync_epoch' => '2025-01-01T00:00:00+00:00']);

    $run = chunkService(WooOrdersSimulator::every(1))->run(SyncEntity::Orders, SyncMode::Full);

    expect(chunkIso($run->cursor_from))->toBe('2024-12-31T23:50:00');
});

it('hands the epoch window to Woo on every page of the run', function () {
    Queue::fake();
    $woo = WooOrdersSimulator::every(3);
    $service = chunkService($woo);
    $run = $service->run(SyncEntity::Orders, SyncMode::Full);

    chunkDrive($service, $run);

    expect($woo->requests)->toHaveCount(2);

    foreach ($woo->requests as $request) {
        expect(chunkIso($request['window']->modifiedAfter))->toBe('2024-09-21T20:20:00')
            ->and(chunkIso($request['window']->modifiedBefore))->toBe('2026-06-01T12:00:00');
    }
});

it('moves the cursor on completion only — to the frozen cursor_to — and a full run is not chunked when its pages fit', function () {
    Queue::fake();
    chunkCursor('2026-06-01 11:30:00+00');
    $service = chunkService(WooOrdersSimulator::every(4)); // 2 pages, limit 2: page 2 has no more
    $run = $service->run(SyncEntity::Orders, SyncMode::Full);

    expect(chunkStored())->toBe('2026-06-01T11:30:00');

    chunkDrive($service, $run);

    expect($run->status)->toBe(SyncStatus::Completed)
        ->and(chunkStored())->toBe('2026-06-01T12:00:00')
        ->and(Order::count())->toBe(4)
        ->and(SyncLog::where('sync_job_id', $run->id)->where('message', 'Run completed')->sole()->context['chunked'])->toBeFalse();
});

it('leaves the stored cursor unchanged when a full run fails, even after pages were synced', function () {
    Queue::fake();
    chunkCursor('2026-06-01 11:30:00+00');
    $service = chunkService(WooOrdersSimulator::every(4, failOnPage: 2));
    $run = $service->run(SyncEntity::Orders, SyncMode::Full);

    expect(fn () => chunkDrive($service, $run))->toThrow(RuntimeException::class);

    $cursor = SyncCursor::findOrFail('orders');
    expect($run->fresh()->status)->toBe(SyncStatus::Failed)
        ->and(Order::count())->toBe(2)
        ->and(chunkIso($cursor->cursor_value))->toBe('2026-06-01T11:30:00')
        ->and($cursor->last_status)->toBe(SyncStatus::Failed);
});

// ================================================================== chunking

it('stops after the configured page count when Woo has more: the run completes and the cursor moves to the last processed order\'s modified time', function () {
    Queue::fake();
    $service = chunkService(WooOrdersSimulator::every(11)); // 6 pages of 2, 20 minutes apart from 06:00
    $run = $service->run(SyncEntity::Orders);

    chunkDrive($service, $run);

    $log = SyncLog::where('sync_job_id', $run->id)->where('message', 'Run completed')->sole();
    expect($run->status)->toBe(SyncStatus::Completed)
        ->and([$run->pages_processed, $run->records_processed])->toBe([2, 4])
        ->and(chunkStored())->toBe('2026-06-01T07:00:00') // the 4th order's modified time, not cursor_to
        ->and(SyncCursor::findOrFail('orders')->last_status)->toBe(SyncStatus::Completed)
        ->and(Order::count())->toBe(4)
        ->and($log->level)->toBe(SyncLogLevel::Info)
        ->and($log->context['chunked'])->toBeTrue();
    Queue::assertPushed(SyncPageJob::class, 2); // page 1 by run(), page 2 by page 1: nothing beyond the limit
    Queue::assertNotPushed(SyncPageJob::class, fn (SyncPageJob $job) => $job->page > 2);
});

it('does not chunk a run whose pages exactly fit the limit: the limit is exceeded only when Woo still has more', function () {
    Queue::fake();
    $service = chunkService(WooOrdersSimulator::every(4));
    $run = $service->run(SyncEntity::Orders);

    chunkDrive($service, $run);

    expect(chunkStored())->toBe('2026-06-01T12:00:00');
});

it('chunks full and incremental runs alike', function (SyncMode $mode) {
    Queue::fake();
    $service = chunkService(WooOrdersSimulator::every(11));
    $run = $service->run(SyncEntity::Orders, $mode);

    chunkDrive($service, $run);

    expect(chunkStored())->toBe('2026-06-01T07:00:00');
})->with([SyncMode::Full, SyncMode::Incremental]);

it('falls back to 500 pages for a limit that is not a positive whole number', function (mixed $limit) {
    Queue::fake();
    config(['woo.sync_max_pages_per_run' => $limit]);
    $service = chunkService(WooOrdersSimulator::every(11));
    $run = $service->run(SyncEntity::Orders);

    chunkDrive($service, $run);

    expect(chunkStored())->toBe('2026-06-01T12:00:00')
        ->and(Order::count())->toBe(11);
})->with(['zero' => [0], 'negative' => [-3], 'text' => ['many'], 'null' => [null]]);

it('starts the next run from the boundary minus the overlap', function () {
    Queue::fake();
    $service = chunkService(WooOrdersSimulator::every(11));
    chunkDrive($service, $service->run(SyncEntity::Orders));

    $next = $service->run(SyncEntity::Orders);

    expect(chunkIso($next->cursor_from))->toBe('2026-06-01T06:50:00');
});

it('never lets a run that was abandoned meanwhile complete its chunk or move the cursor', function () {
    Queue::fake();
    chunkCursor('2026-06-01 05:00:00+00');
    $service = chunkService(WooOrdersSimulator::every(11));
    $run = $service->run(SyncEntity::Orders);
    SyncLog::created(function (SyncLog $log) use ($run) {
        if ($log->message === 'Page synced' && ($log->context['page'] ?? null) === 2) {
            SyncJob::whereKey($run->id)->update(['status' => SyncStatus::Failed->value, 'error' => 'abandoned']);
        }
    });

    chunkDrive($service, $run);

    expect($run->fresh()->status)->toBe(SyncStatus::Failed)
        ->and(chunkStored())->toBe('2026-06-01T05:00:00');
});

// ================================================================== the stall guard

it('fails the run instead of looping when the chunk cannot move the next window forward', function () {
    Queue::fake();
    chunkCursor('2026-06-01 11:00:00+00'); // window starts 10:50; the chunk ends at 10:53, so the next one would start at 10:43
    $service = chunkService(new WooOrdersSimulator(['2026-06-01 10:52:00', '2026-06-01 10:53:00', '2026-06-01 10:54:00', '2026-06-01 10:55:00']));
    config(['woo.sync_max_pages_per_run' => 1]);
    $run = $service->run(SyncEntity::Orders);

    expect(fn () => chunkDrive($service, $run))->toThrow(SyncStalledException::class);

    $cursor = SyncCursor::findOrFail('orders');
    expect($run->fresh()->status)->toBe(SyncStatus::Failed)
        ->and($run->fresh()->error)->toContain('SyncStalledException')->toContain('no progress')
        ->and(chunkIso($cursor->cursor_value))->toBe('2026-06-01T11:00:00')
        ->and($cursor->consecutive_failures)->toBe(1);
    Queue::assertPushed(SyncPageJob::class, 1);
    expect(SyncLog::where('sync_job_id', $run->id)->where('level', SyncLogLevel::Error)->count())->toBe(1);
});

it('needs the next window to start strictly later: an equal start is a stall, one second more is progress', function (string $lastOnPage, bool $advances) {
    Queue::fake();
    chunkCursor('2026-06-01 11:00:00+00'); // window starts 10:50:00
    $service = chunkService(new WooOrdersSimulator(['2026-06-01 10:59:00', $lastOnPage, '2026-06-01 11:20:00', '2026-06-01 11:30:00']));
    config(['woo.sync_max_pages_per_run' => 1]);
    $run = $service->run(SyncEntity::Orders);

    try {
        chunkDrive($service, $run);
    } catch (SyncStalledException) {
    }

    expect($run->fresh()->status)->toBe($advances ? SyncStatus::Completed : SyncStatus::Failed)
        ->and(chunkStored())->toBe($advances ? substr(str_replace(' ', 'T', $lastOnPage), 0, 19) : '2026-06-01T11:00:00');
})->with([
    'the next window would start where this one did' => ['2026-06-01 11:00:00', false],
    'one second later' => ['2026-06-01 11:00:01', true],
]);

it('cannot stall on the very first sync, which has no lower bound', function () {
    Queue::fake();
    $service = chunkService(new WooOrdersSimulator(['2026-06-01 10:52:00', '2026-06-01 10:53:00', '2026-06-01 10:54:00', '2026-06-01 10:55:00']));
    config(['woo.sync_max_pages_per_run' => 1]);
    $run = $service->run(SyncEntity::Orders);

    chunkDrive($service, $run);

    expect($run->status)->toBe(SyncStatus::Completed)
        ->and(chunkStored())->toBe('2026-06-01T10:53:00');
});

// ================================================================== end to end: the scheduled runs finish the job

it('finishes a chunked full sync over the following runs: every order lands, nothing is lost, the last run closes at cursor_to', function () {
    chunkCursor('2026-06-01 11:50:00+00'); // a recent cursor: the full run goes back to the epoch, and the first chunk moves the cursor back
    $woo = WooOrdersSimulator::every(11);
    app()->instance(WooClient::class, $woo);

    $cursors = [];

    foreach ([['--full' => true], [], [], []] as $options) {
        expect(Artisan::call('hm:sync', $options))->toBe(0);
        $cursors[] = chunkStored();
    }

    $runs = SyncJob::orderBy('id')->get();
    expect($runs->pluck('mode')->all())->toBe([SyncMode::Full, SyncMode::Incremental, SyncMode::Incremental, SyncMode::Incremental])
        ->and($runs->pluck('status')->unique()->all())->toBe([SyncStatus::Completed])
        ->and($runs->pluck('pages_processed')->all())->toBe([2, 2, 2, 1])
        ->and($cursors)->toBe(['2026-06-01T07:00:00', '2026-06-01T08:00:00', '2026-06-01T09:00:00', '2026-06-01T12:00:00'])
        ->and(Order::count())->toBe(11)
        ->and(Order::pluck('woo_order_id')->sort()->values()->all())->toBe(range(9000, 9010));
});

it('finishes an incremental catch-up the same way, however far behind the cursor is', function () {
    chunkCursor('2026-06-01 06:00:00+00');
    app()->instance(WooClient::class, WooOrdersSimulator::every(11));

    $cursors = [];

    for ($i = 0; $i < 4; $i++) {
        Artisan::call('hm:sync');
        $cursors[] = chunkStored();
    }

    expect($cursors)->toBe(['2026-06-01T07:00:00', '2026-06-01T08:00:00', '2026-06-01T09:00:00', '2026-06-01T12:00:00'])
        ->and(Order::count())->toBe(11);
});

it('is idempotent across the overlap: orders re-read by a later run are unchanged', function () {
    app()->instance(WooClient::class, WooOrdersSimulator::every(11));

    foreach ([['--full' => true], [], [], []] as $options) {
        Artisan::call('hm:sync', $options);
    }
    $state = Order::orderBy('woo_order_id')->get()->map->only(['id', 'woo_order_id', 'total', 'status'])->all();

    Artisan::call('hm:sync');

    expect(Order::orderBy('woo_order_id')->get()->map->only(['id', 'woo_order_id', 'total', 'status'])->all())->toEqual($state)
        ->and(Order::count())->toBe(11);
});
