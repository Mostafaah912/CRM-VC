<?php

declare(strict_types=1);

namespace App\Modules\Sync\Jobs;

use App\Modules\Sync\Services\ReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Reconciles ONE Jalali month and stores the result in reconciliation_reports (P2-11). No logic: it calls
 * ReconciliationService, which stores the report — or, on failure, a `failed` row with the scrubbed reason. Unique per
 * month; two attempts (one retry after a pause) because a month is read through many sequential Woo requests and one stalled
 * request (30 s client timeout + the retry ladder) can eat minutes. failed() is the safety net for a worker that died or timed
 * out on the last attempt (idempotent); the next nightly run reconciles a still-failed month again.
 */
final class ReconcileMonthJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    /**
     * Reading a month is one list request per 50 orders, ~52-65 ms per order: months of 2,375 / 3,014 / 3,169 orders took 128 / 158 s
     * and (expected) ~165 s on staging, but 1405-04 (3,169 orders) was killed by the old 300 s limit — one slow Woo response (30 s client
     * timeout + a retry ladder of 2/8/30/120 s) is enough to double a month. 900 s gives a 3,169-order month ~5x its expected time and
     * still reads ~15,000 orders at 60 ms. Ladder that must hold (a test checks it): this timeout < Horizon's supervisor timeout (1800) <
     * queue retry_after (1830), or a running month is re-reserved and runs twice; uniqueFor covers both attempts plus the pause.
     * A month beyond ~15,000 orders needs paging (a month is now ≤ ~3,200).
     */
    public int $timeout = 900;

    /** One retry after a pause: lets a slow Woo / rate-limit window clear instead of failing the month at once. */
    public int $backoff = 120;

    public int $uniqueFor = 2400;

    public function __construct(public readonly string $jalaliMonth)
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return $this->jalaliMonth;
    }

    public function handle(ReconciliationService $reconciliation): void
    {
        $reconciliation->reconcile($this->jalaliMonth);
    }

    public function failed(Throwable $exception): void
    {
        app(ReconciliationService::class)->recordFailure($this->jalaliMonth, $exception);
    }
}
