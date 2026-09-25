<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Metrics\Jobs\RecomputeMetricsJob;
use Illuminate\Console\Command;

/** `metrics:recompute` (PRD §11/§26 nightly chain step 6): runs the full pipeline synchronously, in-process. */
final class MetricsRecompute extends Command
{
    protected $signature = 'metrics:recompute {--dirty : Only recompute customers.metrics_dirty = true customers}';

    protected $description = 'Recompute customer metrics (RFM, CLV, churn, lifecycle)';

    public function handle(): int
    {
        $runType = $this->option('dirty') ? 'dirty' : 'full';

        RecomputeMetricsJob::dispatchSync($runType);

        $this->info("Metrics recomputed ({$runType}).");

        return self::SUCCESS;
    }
}
