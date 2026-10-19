<?php

declare(strict_types=1);

namespace App\Modules\Ai\Jobs;

use App\Modules\Ai\Enums\AiInsightType;
use App\Modules\Ai\Services\ManualGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * P7-09a: the queued half of the «تولید دستی» button (a real generation is 20-45 s, so it is never an HTTP request — CLAUDE.md §1).
 * Thin: resolves ManualGenerationService and calls run(), which records the outcome for the screen to poll. No retries: a retry is a
 * second paid call the user did not ask for (the person clicks again). Unique per type; timeout/uniqueFor follow the scheduled jobs
 * (worst case of one run, see AiJobTimeoutTest).
 */
final class GenerateManualInsightJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 1200;

    public function __construct(public readonly AiInsightType $type, public readonly int $userId, public readonly bool $force = true)
    {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return 'ai-manual-'.$this->type->value;
    }

    public function handle(ManualGenerationService $manual): void
    {
        $manual->run($this->type, $this->userId, $this->force);
    }

    public function failed(Throwable $e): void
    {
        // Killed (timeout) or crashed outside run(): free the screen instead of leaving it "running" for the cache TTL.
        app(ManualGenerationService::class)->markCrashed($this->type, $this->userId);
    }
}
