<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Jobs;

use App\Modules\Analytics\Services\CustomerPurchaseAggregateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queues CustomerPurchaseAggregateService::rebuild() (PRD §16/§22) — a thin wrapper, no business logic
 * here (CLAUDE.md §1/§5: "a Job resolves a Service and calls it").
 *
 * Queue = `metrics` (PRD §22 only names critical/sync/metrics/ai/default; Analytics has no queue of its
 * own — the same choice already made for `RebuildAllSegmentsJob`): PRD §22's nightly chain runs this
 * right after `RecomputeMetricsJob('full')`, so it belongs on the same worker pool instead of racing
 * ahead of it on `default`.
 *
 * `tries = 1`, `ShouldBeUnique` + `uniqueFor > timeout` (PRD §22's rule for every whole-data job): a
 * second rebuild racing the first would TRUNCATE the table the first one is still inserting into.
 */
final class BuildCustomerPurchaseAggregatesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue('metrics');
    }

    public function uniqueId(): string
    {
        return 'build-customer-purchase-aggregates';
    }

    public function handle(CustomerPurchaseAggregateService $aggregates): void
    {
        $summary = $aggregates->rebuild();

        Log::info('BuildCustomerPurchaseAggregatesJob finished', [
            'product_rows' => $summary->productRows,
            'category_rows' => $summary->categoryRows,
            'elapsed_ms' => $summary->elapsedMs,
        ]);
    }
}
