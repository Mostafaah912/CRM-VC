<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Events;

use App\Modules\Metrics\Models\MetricRun;
use Illuminate\Foundation\Events\Dispatchable;

/** A metric_runs row finished successfully (PRD §11 step 12). Carries the run itself, not customer data. */
final class MetricsRecomputed
{
    use Dispatchable;

    public function __construct(public readonly MetricRun $run) {}
}
