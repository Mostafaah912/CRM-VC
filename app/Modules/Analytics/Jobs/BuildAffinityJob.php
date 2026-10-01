<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Jobs;

use App\Modules\Analytics\Services\AffinityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queues AffinityService::rebuild() (PRD §16/§22, P6-05) — a thin wrapper, no business logic here
 * (CLAUDE.md §1/§5). Queue = `metrics`, same choice already made for `BuildCustomerPurchaseAggregatesJob`
 * (PRD §22 names no queue of its own for Analytics).
 *
 * Scheduling (PRD §22: "weekly Sat 04:00 BuildAffinityJob") is P6-09's "full scheduler chain" — not
 * wired here, same precedent as P6-01 through P6-04's Jobs, which also shipped without their own
 * schedule entry.
 *
 * `tries = 1`, `ShouldBeUnique` + `uniqueFor > timeout` (PRD §22's rule for every whole-data job): a
 * second rebuild racing the first would TRUNCATE `product_affinities` while the first is still inserting.
 */
final class BuildAffinityJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 1200;

    public function __construct()
    {
        $this->onQueue('metrics');
    }

    public function uniqueId(): string
    {
        return 'build-affinity';
    }

    public function handle(AffinityService $affinity): void
    {
        $summary = $affinity->rebuild();

        Log::info('BuildAffinityJob finished', [
            'category_rows' => $summary->categoryRows,
            'product_rows' => $summary->productRows,
            'variation_rows' => $summary->variationRows,
            'basket_rows' => $summary->basketRows,
            'elapsed_ms' => $summary->elapsedMs,
        ]);
    }
}
