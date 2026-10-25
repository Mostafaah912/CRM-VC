<?php

declare(strict_types=1);

/*
| P8-03g — a single Woo request is BOUNDED, and the bound is written down. Measured on staging (70 requests): Woo answers any request in
| 2.3-4.9 s, so a 20 s timeout is four times the p90. Worst case for ONE request = (1 + max_retries) x timeout + the backoff ladder. It used to
| be 5 x 30 + 160 = 310 s (PRD §10: four retries at 2/8/30/120 s), enough for one stall to eat a fifth of a job's budget; now 4 x 20 + 40 = 120 s.
*/

it('bounds one Woo request to at most two minutes: (1 + retries) x timeout + the backoff actually used', function () {
    $timeout = (int) config('woo.timeout');
    $retries = (int) config('woo.max_retries');
    $ladder = array_values((array) config('woo.retry_backoff_seconds'));
    $backoff = 0;

    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        $backoff += $ladder[min($attempt, count($ladder)) - 1];
    }

    expect([$timeout, $retries, $ladder])->toBe([20, 3, [2, 8, 30]])
        ->and((1 + $retries) * $timeout + $backoff)->toBe(120)
        ->and((1 + $retries) * $timeout + $backoff)->toBeLessThanOrEqual(120);
});

it('keeps the timeout comfortably above what Woo really takes (p90 4.9 s) so a healthy request is never cut short', function () {
    expect((int) config('woo.timeout'))->toBeGreaterThanOrEqual(4 * 5);
});
