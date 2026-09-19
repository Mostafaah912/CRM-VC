<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Enums\SyncMode;
use App\Modules\Sync\Enums\SyncStatus;
use App\Modules\Sync\Exceptions\WooFixtureNotFoundException;
use App\Modules\Sync\Jobs\SyncEntityJob;
use App\Modules\Sync\Jobs\SyncPageJob;
use App\Modules\Sync\Models\SyncCursor;
use App\Modules\Sync\Models\SyncJob;
use App\Modules\Sync\Services\FakeWooClient;
use App\Modules\Sync\Services\SyncService;
use App\Modules\Sync\Services\WooClient;
use App\Modules\Sync\Support\WooFixture;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WooFixtures;

/*
| P2-08 — the two jobs. They hold no business logic: a job resolves SyncService and calls it (CLAUDE.md §1).
| Both are ShouldBeUnique (PRD §22) with uniqueFor above their timeout, on the `sync` queue, tries = 1
| (HttpWooClient already retries transient Woo errors; a failed run is simply started again from the same cursor).
| phpunit.xml uses the `sync` queue driver, so dispatching runs the whole chain inline against the FakeWooClient.
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 12:00:00', 'UTC'));
});

function jobsClient(?FakeWooClient $fake = null): void
{
    app()->instance(WooClient::class, $fake ?? WooFixtures::client());
}

// ================================================================== shape

it('makes both jobs queued, unique, on the sync queue, with a single attempt', function () {
    $entity = new SyncEntityJob(SyncEntity::Orders);
    $page = new SyncPageJob(41, 3);

    foreach ([$entity, $page] as $job) {
        expect($job)->toBeInstanceOf(ShouldQueue::class)
            ->toBeInstanceOf(ShouldBeUnique::class)
            ->and($job->queue)->toBe('sync')
            ->and($job->tries)->toBe(1);
    }
});

it('makes the entity job unique per entity and the page job unique per run and page, for 600 seconds', function () {
    expect((new SyncEntityJob(SyncEntity::Orders))->uniqueId())->toBe('orders')
        ->and((new SyncPageJob(41, 3))->uniqueId())->toBe('41:3')
        ->and((new SyncPageJob(41, 4))->uniqueId())->not->toBe((new SyncPageJob(41, 3))->uniqueId())
        ->and((new SyncPageJob(42, 3))->uniqueId())->not->toBe((new SyncPageJob(41, 3))->uniqueId())
        ->and((new SyncPageJob(41, 3))->uniqueFor)->toBe(600);
});

it('keeps uniqueFor above each job\'s timeout, and the timeout below the queue\'s retry_after so a running page is never re-reserved', function () {
    $entity = new SyncEntityJob(SyncEntity::Orders);
    $page = new SyncPageJob(41, 3);
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    expect($entity->uniqueFor)->toBeGreaterThan($entity->timeout)
        ->and($page->uniqueFor)->toBeGreaterThan($page->timeout)
        ->and($page->timeout)->toBeLessThan($retryAfter)
        ->and($entity->timeout)->toBeLessThan($retryAfter);
});

it('defaults the entity job to an incremental run and carries its mode', function () {
    expect((new SyncEntityJob(SyncEntity::Orders))->mode)->toBe(SyncMode::Incremental)
        ->and((new SyncEntityJob(SyncEntity::Orders, SyncMode::Full))->mode)->toBe(SyncMode::Full);
});

// ================================================================== uniqueness in practice

it('does not queue an identical entity job twice while the first is unprocessed', function () {
    Queue::fake();

    SyncEntityJob::dispatch(SyncEntity::Orders);
    SyncEntityJob::dispatch(SyncEntity::Orders);

    Queue::assertPushed(SyncEntityJob::class, 1);
});

it('queues page jobs once per run and page', function () {
    Queue::fake();

    SyncPageJob::dispatch(7, 1);
    SyncPageJob::dispatch(7, 1);
    SyncPageJob::dispatch(7, 2);
    SyncPageJob::dispatch(8, 1);

    Queue::assertPushed(SyncPageJob::class, 3);
});

// ================================================================== the jobs only call the service

it('lets the entity job start a run through the service and nothing else', function () {
    Queue::fake();
    jobsClient();

    (new SyncEntityJob(SyncEntity::Orders))->handle(app(SyncService::class));

    $run = SyncJob::sole();
    expect($run->status)->toBe(SyncStatus::Running);
    Queue::assertPushed(SyncPageJob::class, fn (SyncPageJob $job) => $job->syncJobId === $run->id && $job->page === 1);
});

it('lets the page job sync exactly its run and page through the service', function () {
    Queue::fake();
    jobsClient();
    $run = app(SyncService::class)->run(SyncEntity::Orders);

    (new SyncPageJob($run->id, 1))->handle(app(SyncService::class));

    expect(Order::count())->toBe(2)
        ->and($run->fresh()->pages_processed)->toBe(1);
    Queue::assertPushed(SyncPageJob::class, fn (SyncPageJob $job) => $job->page === 2);
});

// ================================================================== the failed() safety net

it('marks a still-running run failed when the worker dies or times out, leaving the cursor alone', function () {
    Queue::fake();
    jobsClient();
    SyncCursor::create(['entity' => 'orders', 'cursor_value' => '2026-06-01 09:00:00+00', 'last_status' => SyncStatus::Completed]);
    $run = app(SyncService::class)->run(SyncEntity::Orders);

    (new SyncPageJob($run->id, 1))->failed(new RuntimeException('killed by timeout'));

    $run->refresh();
    $cursor = SyncCursor::findOrFail('orders');
    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->error)->toContain('RuntimeException')->toContain('killed by timeout')
        ->and($cursor->cursor_value?->utc()->format('Y-m-d\TH:i:s'))->toBe('2026-06-01T09:00:00')
        ->and($cursor->consecutive_failures)->toBe(1);
});

it('does nothing when the failed hook fires for a run that already finished or failed', function (SyncStatus $status) {
    jobsClient();
    $run = SyncJob::create([
        'entity' => SyncEntity::Orders, 'mode' => SyncMode::Incremental, 'status' => $status,
        'cursor_to' => CarbonImmutable::now('UTC'), 'started_at' => CarbonImmutable::now('UTC'), 'error' => $status === SyncStatus::Failed ? 'first reason' : null,
    ]);

    (new SyncPageJob($run->id, 1))->failed(new RuntimeException('late'));

    expect($run->fresh()->status)->toBe($status)
        ->and($run->fresh()->error)->toBe($status === SyncStatus::Failed ? 'first reason' : null);
})->with([SyncStatus::Completed, SyncStatus::Failed]);

it('ignores the failed hook of a page job whose run no longer exists', function () {
    jobsClient();

    (new SyncPageJob(987654, 1))->failed(new RuntimeException('orphan'));

    expect(SyncJob::count())->toBe(0);
});

// ================================================================== the whole chain, through the queue driver

it('runs a whole run through the queue: entity job, then a page job per page, then completed with the cursor advanced', function () {
    jobsClient();

    SyncEntityJob::dispatch(SyncEntity::Orders);

    $run = SyncJob::sole();
    $cursor = SyncCursor::findOrFail('orders');
    expect($run->status)->toBe(SyncStatus::Completed)
        ->and([$run->pages_processed, $run->records_processed])->toBe([2, 3])
        ->and(Order::count())->toBe(3)
        ->and($cursor->cursor_value?->equalTo($run->cursor_to))->toBeTrue()
        ->and($cursor->last_status)->toBe(SyncStatus::Completed);
    Http::assertNothingSent();
});

it('fails the run once, cursor unchanged, when a page fails inside the queue — the exception still surfaces', function () {
    jobsClient(new FakeWooClient(array_values(array_filter(
        WooFixtures::all(),
        fn (WooFixture $f) => $f->endpoint === 'orders' && $f->page === 1 && $f->query === [],
    ))));

    expect(fn () => SyncEntityJob::dispatch(SyncEntity::Orders))->toThrow(WooFixtureNotFoundException::class);

    $run = SyncJob::sole();
    $cursor = SyncCursor::findOrFail('orders');
    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($cursor->cursor_value)->toBeNull()
        ->and($cursor->consecutive_failures)->toBe(1) // service catch + failed() hook both fired; the count is derived, so still 1
        ->and(Order::count())->toBe(2);
});

it('starts nothing when a fresh run is already going, even through the queue', function () {
    jobsClient();
    $fresh = SyncJob::create([
        'entity' => SyncEntity::Orders, 'mode' => SyncMode::Incremental, 'status' => SyncStatus::Running,
        'cursor_to' => CarbonImmutable::now('UTC')->subMinutes(5), 'started_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);

    SyncEntityJob::dispatch(SyncEntity::Orders);

    expect(SyncJob::count())->toBe(1)
        ->and($fresh->fresh()->status)->toBe(SyncStatus::Running)
        ->and(Order::count())->toBe(0);
});
