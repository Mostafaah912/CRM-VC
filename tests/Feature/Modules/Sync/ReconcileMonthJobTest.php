<?php

declare(strict_types=1);

use App\Modules\Sync\Enums\ReconciliationStatus;
use App\Modules\Sync\Jobs\ReconcileMonthJob;
use App\Modules\Sync\Models\ReconciliationReportModel;
use App\Modules\Sync\Services\ReconciliationService;
use App\Modules\Sync\Services\WooClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WooOrderTotalsSimulator;

/*
| P2-11 — ReconcileMonthJob: reconcile ONE Jalali month and write the result to reconciliation_reports. It holds no logic
| (it calls ReconciliationService), runs on the `sync` queue with a single attempt, is unique per month, and on failure the
| month's row is marked `failed` with the (scrubbed) error. phpunit.xml uses the sync queue driver: dispatch runs inline.
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2025-01-10 12:00:00', 'UTC'));
});

function jobWoo(?WooOrderTotalsSimulator $woo = null): void
{
    app()->instance(WooClient::class, $woo ?? new WooOrderTotalsSimulator([]));
}

it('is a queued, unique job on the sync queue with a single attempt', function () {
    $job = new ReconcileMonthJob('1403-07');

    expect($job)->toBeInstanceOf(ShouldQueue::class)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->queue)->toBe('sync')
        ->and($job->tries)->toBe(1)
        ->and($job->jalaliMonth)->toBe('1403-07');
});

it('is unique per month, for 600 seconds, above its timeout and below the queue\'s retry_after', function () {
    $job = new ReconcileMonthJob('1403-07');

    expect($job->uniqueId())->toBe('1403-07')
        ->and((new ReconcileMonthJob('1403-08'))->uniqueId())->not->toBe($job->uniqueId())
        ->and($job->uniqueFor)->toBe(600)
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout)
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.redis.retry_after'));
});

it('queues a month once while its job is waiting, and other months separately', function () {
    Queue::fake();

    ReconcileMonthJob::dispatch('1403-07');
    ReconcileMonthJob::dispatch('1403-07');
    ReconcileMonthJob::dispatch('1403-08');

    Queue::assertPushed(ReconcileMonthJob::class, 2);
});

it('reconciles its month through the service and writes the row', function () {
    jobWoo();

    (new ReconcileMonthJob('1403-07'))->handle(app(ReconciliationService::class));

    $row = ReconciliationReportModel::sole();
    expect([$row->jalali_month, $row->status])->toBe(['1403-07', ReconciliationStatus::Green]);
});

it('runs end to end through the queue driver', function () {
    jobWoo();

    ReconcileMonthJob::dispatch('1403-08');

    expect(ReconciliationReportModel::sole()->jalali_month)->toBe('1403-08');
});

it('marks the month failed when the reconciliation fails inside the queue — the exception still surfaces, one failed row', function () {
    jobWoo(new WooOrderTotalsSimulator([], failure: new RuntimeException('woo is down')));

    expect(fn () => ReconcileMonthJob::dispatch('1403-07'))->toThrow(RuntimeException::class);

    $row = ReconciliationReportModel::sole();
    expect($row->status)->toBe(ReconciliationStatus::Failed)->and($row->error_message)->toContain('RuntimeException')->toContain('woo is down');
});

it('marks the month failed from the failed() hook too, for a worker that died or timed out', function () {
    jobWoo();

    (new ReconcileMonthJob('1403-07'))->failed(new RuntimeException('killed by timeout'));

    $row = ReconciliationReportModel::sole();
    expect($row->status)->toBe(ReconciliationStatus::Failed)->and($row->error_message)->toContain('killed by timeout')
        ->and($row->period_start->toDateString())->toBe('2024-09-22');
});

it('writes nothing from the failed() hook for a month that cannot be reconciled', function () {
    jobWoo();

    (new ReconcileMonthJob('1403-10'))->failed(new RuntimeException('late'));
    (new ReconcileMonthJob('nonsense'))->failed(new RuntimeException('late'));

    expect(ReconciliationReportModel::count())->toBe(0);
});

it('refuses a month that is not complete, leaving no row', function () {
    jobWoo();

    expect(fn () => (new ReconcileMonthJob('1403-10'))->handle(app(ReconciliationService::class)))->toThrow(InvalidArgumentException::class);

    expect(ReconciliationReportModel::count())->toBe(0);
});
