<?php

declare(strict_types=1);

use App\Modules\Sync\Jobs\ReconcileMonthJob;
use App\Modules\Sync\Jobs\ReconcileRecentMonthsJob;
use App\Modules\Sync\Services\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Queue;

/*
| P6-09 — the nightly chain's last step, PRD §22's "ReconcileJob(2)": queues ReconcileMonthJob for only the
| last N complete months (ReconciliationService::dispatchRecentMonths(), already tested there). No logic
| here (CLAUDE.md §1/§5): a Job resolves a Service and calls it.
*/

it('implements ShouldBeUnique, on the sync queue, with a single attempt', function () {
    $job = new ReconcileRecentMonthsJob(2);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->queue)->toBe('sync')
        ->and($job->tries)->toBe(1)
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout);
});

it('dispatches ReconcileMonthJob for the last N complete months when handled', function () {
    // handle() directly, not dispatchSync(): Dispatcher::dispatchSync() routes a ShouldQueue job through
    // dispatchToQueue()->onConnection('sync'), which Queue::fake() intercepts as a recorded push rather
    // than actually running it — the same reason ReconciliationService's own dispatchAllMonths() test
    // calls the service method directly instead of going through the Bus.
    Queue::fake();
    test()->travelTo(CarbonImmutable::parse('2025-01-10 12:00:00', 'UTC')); // last complete 1403-09

    (new ReconcileRecentMonthsJob(2))->handle(app(ReconciliationService::class));

    Queue::assertPushed(ReconcileMonthJob::class, 2);
    Queue::assertPushed(ReconcileMonthJob::class, fn (ReconcileMonthJob $job) => $job->jalaliMonth === '1403-09');
    Queue::assertPushed(ReconcileMonthJob::class, fn (ReconcileMonthJob $job) => $job->jalaliMonth === '1403-08');
    Queue::assertNotPushed(ReconcileMonthJob::class, fn (ReconcileMonthJob $job) => $job->jalaliMonth === '1403-07');
});
