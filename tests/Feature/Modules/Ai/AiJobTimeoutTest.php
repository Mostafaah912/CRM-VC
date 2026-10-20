<?php

declare(strict_types=1);

use App\Modules\Ai\Jobs\GenerateDailyBriefJob;
use App\Modules\Ai\Jobs\GenerateWeeklyReviewJob;

/*
| A job killed by its own timeout mid-call loses a paid answer, and a job re-reserved by the queue (retry_after) runs twice. So:
| job timeout >= the worst case of one run  AND  uniqueFor > timeout  AND  queue retry_after >= timeout + 30  AND  Horizon timeout >= timeout.
*/
dataset('ai jobs', [GenerateDailyBriefJob::class, GenerateWeeklyReviewJob::class]);

it('defaults the HTTP timeout to 120 seconds', function () {
    expect((require base_path('config/ai.php'))['timeout'])->toBe(120);
});

it('gives each generation job more time than its worst case, and the queue more than the job', function (string $class) {
    $job = new $class;
    $attempts = (int) config('ai.max_retries') + 1;
    $backoff = array_sum(array_slice((array) config('ai.retry_backoff_seconds'), 0, (int) config('ai.max_retries')));
    $oneCall = $attempts * (int) config('ai.timeout') + $backoff;
    $worstRun = 2 * $oneCall + 60;   // generate + one corrective retry + context build

    expect($job->timeout)->toBeGreaterThanOrEqual($worstRun)
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout)
        ->and((int) config('queue.connections.redis.retry_after'))->toBeGreaterThanOrEqual($job->timeout + 30)
        ->and((int) collect(config('horizon.defaults'))->pluck('timeout')->min())->toBeGreaterThanOrEqual($job->timeout);
})->with('ai jobs');
