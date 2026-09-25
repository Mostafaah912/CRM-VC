<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Events;

use App\Modules\Metrics\Enums\MetricRunMode;
use App\Modules\Metrics\Models\MetricRun;
use Illuminate\Foundation\Events\Dispatchable;

/** A metric_runs row finished successfully (PRD §11 step 12). Carries the run itself, not customer data. */
final class MetricsRecomputed
{
    use Dispatchable;

    public function __construct(public readonly MetricRun $run) {}

    /**
     * P5-08: other modules (e.g. Segments' RebuildSegmentsAfterMetricsRecomputed listener) need this
     * check without importing Metrics\Enums\MetricRunMode themselves — a cross-module boundary this
     * class, not its enum, is allowed to cross (tests/Arch/ArchitectureTest.php: only Services/Events).
     */
    public function isFullRun(): bool
    {
        return $this->run->mode === MetricRunMode::Full;
    }
}
