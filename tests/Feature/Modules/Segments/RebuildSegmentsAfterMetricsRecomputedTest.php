<?php

declare(strict_types=1);

use App\Modules\Metrics\Enums\MetricRunMode;
use App\Modules\Metrics\Events\MetricsRecomputed;
use App\Modules\Metrics\Services\MetricRunService;
use App\Modules\Segments\Jobs\RebuildAllSegmentsJob;
use App\Modules\Segments\Listeners\RebuildSegmentsAfterMetricsRecomputed;
use Illuminate\Support\Facades\Bus;

/*
| P5-08. PRD §22's Scheduler puts RebuildAllSegmentsJob only in the nightly chain, right after
| RecomputeMetricsJob('full') — never in the `hourly:` line (RecomputeMetricsJob('dirty') only). This
| listener follows that literally: dispatch on a full run, never on a dirty one.
*/

it('dispatches RebuildAllSegmentsJob after a full metrics run', function () {
    Bus::fake();

    $run = app(MetricRunService::class)->start(MetricRunMode::Full);

    (new RebuildSegmentsAfterMetricsRecomputed)->handle(new MetricsRecomputed($run));

    Bus::assertDispatched(RebuildAllSegmentsJob::class);
});

it('does not dispatch RebuildAllSegmentsJob after an hourly dirty metrics run', function () {
    Bus::fake();

    $run = app(MetricRunService::class)->start(MetricRunMode::Dirty);

    (new RebuildSegmentsAfterMetricsRecomputed)->handle(new MetricsRecomputed($run));

    Bus::assertNotDispatched(RebuildAllSegmentsJob::class);
});

it('MetricsRecomputed::isFullRun() reflects the run\'s mode', function () {
    $full = app(MetricRunService::class)->start(MetricRunMode::Full);
    $dirty = app(MetricRunService::class)->start(MetricRunMode::Dirty);

    expect((new MetricsRecomputed($full))->isFullRun())->toBeTrue()
        ->and((new MetricsRecomputed($dirty))->isFullRun())->toBeFalse();
});
