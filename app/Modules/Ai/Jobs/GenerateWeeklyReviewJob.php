<?php

declare(strict_types=1);

namespace App\Modules\Ai\Jobs;

use App\Modules\Ai\Enums\AiInsightType;
use App\Modules\Ai\Services\AnalystService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PRD §22 "weekly Sat 07:30 GenerateWeeklyReviewJob" (queue `ai`). Thin: resolves AnalystService and calls runScheduled() — every refusal
 * (AI off, missing key/price, chain not finished, stale data, budget, invalid answer) is decided there and returns an outcome; only a
 * RETRYABLE provider error (rate limit / timeout / 5xx after the driver's own short retries) is rethrown so the queue retries it, with
 * backoff. tries are small on purpose: every attempt can cost money. Unique, with uniqueFor > timeout (PRD §22).
 */
final class GenerateWeeklyReviewJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    // Worst case of ONE run: the insight generator makes up to 2 provider calls (a corrective retry), each up to (max_retries + 1) HTTP
    // attempts of ai.timeout (120 s) plus the driver backoff (2 + 8 s) = 2 x 370 s = 740 s, plus ~60 s to build the context. The queue's
    // retry_after (1830) and Horizon's timeout (1800) both sit above this (tests/Feature/Modules/Ai/AiJobTimeoutTest.php).
    public int $timeout = 900;

    public int $uniqueFor = 1200;

    /** @var list<int> */
    public array $backoff = [300, 900];

    public function __construct()
    {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return 'ai-weekly-review';
    }

    public function handle(AnalystService $analyst): void
    {
        $outcome = $analyst->runScheduled(AiInsightType::WeeklyReview);

        Log::info('GenerateWeeklyReviewJob finished', ['status' => $outcome->status, 'reason' => $outcome->reason, 'insight_id' => $outcome->insightId]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('GenerateWeeklyReviewJob failed', ['exception' => $e::class]);
    }
}
