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
 * month (uniqueFor 600); a single attempt: a failed month is simply reconciled again by the next nightly run. failed() is
 * the safety net for a worker that died or timed out mid-month (idempotent).
 */
final class ReconcileMonthJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** Reading a month is one list request per 50 orders. Must stay below the queue's retry_after (90), or a running job would be re-reserved. */
    public int $timeout = 80;

    public int $uniqueFor = 600;

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
