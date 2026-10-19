<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Analyst\AnalystPeriods;
use App\Modules\Ai\Analyst\InsightGenerator;
use App\Modules\Ai\Analyst\PromptRepository;
use App\Modules\Ai\Analyst\ResponseCache;
use App\Modules\Ai\Context\ContextBuilder;
use App\Modules\Ai\Context\ContextPiiException;
use App\Modules\Ai\Context\ContextTooLargeException;
use App\Modules\Ai\Dto\AnalystPeriod;
use App\Modules\Ai\Dto\AnalystResult;
use App\Modules\Ai\Dto\BuiltContext;
use App\Modules\Ai\Dto\GeneratedInsight;
use App\Modules\Ai\Dto\ScheduledOutcome;
use App\Modules\Ai\Enums\AiInsightType;
use App\Modules\Ai\Events\AiGenerationFailed;
use App\Modules\Ai\Events\AiInsightGenerated;
use App\Modules\Ai\Exceptions\AiProviderException;
use App\Modules\Ai\Exceptions\AiResponseInvalidException;
use App\Modules\Ai\Exceptions\AnalystNotReadyException;
use App\Modules\Ai\Exceptions\BudgetExceededException;
use App\Modules\Ai\Exceptions\ManualRequestThrottledException;
use App\Modules\Ai\Exceptions\StaleDataException;
use App\Modules\Ai\Exceptions\UnpricedModelException;
use App\Modules\Ai\Models\AiInsight;
use App\Modules\Ai\Models\AiToolCall;
use App\Modules\Metrics\Services\MetricRunService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * PRD §07 AnalystService: build the context, reuse a fresh identical insight (zero calls, zero spend) or generate through the
 * BUDGETED provider, validate, and store the insight plus its ai_tool_calls in ONE transaction — a failure at any step leaves no row.
 * The AI is read-only and writes nothing but these two tables.
 *
 * Failure policy: not ready / stale data / throttled -> refused BEFORE any provider call; BudgetExceeded and UnpricedModel pass through
 * untouched (the budget layer already alerted); an invalid answer (after the one corrective retry) and a context problem raise
 * AiGenerationFailed (an alert, no model text, no PII).
 */
final class AnalystService
{
    public function __construct(
        private readonly ContextBuilder $contexts,
        private readonly InsightGenerator $generator,
        private readonly ResponseCache $cache,
        private readonly AnalystReadiness $readiness,
        private readonly AnalystPeriods $periods,
        private readonly PromptRepository $prompts,
        private readonly MetricRunService $metricRuns,
    ) {}

    public function dailyBrief(?int $requestedBy = null, bool $force = false): AnalystResult
    {
        return $this->generate(AiInsightType::DailyBrief, $this->periods->daily(), $requestedBy, $force);
    }

    public function weeklyReview(?int $requestedBy = null, bool $force = false): AnalystResult
    {
        return $this->generate(AiInsightType::WeeklyReview, $this->periods->weekly(), $requestedBy, $force);
    }

    public function generate(AiInsightType $type, AnalystPeriod $period, ?int $requestedBy = null, bool $force = false): AnalystResult
    {
        $problem = $this->readiness->generationProblem();

        if ($problem !== null) {
            throw new AnalystNotReadyException($problem, $this->readiness->describe($problem));
        }

        try {
            $context = $this->contexts->build($type);
        } catch (ContextPiiException|ContextTooLargeException $e) {
            event(new AiGenerationFailed($type->value, 'context_error', $e::class));

            throw $e;
        }

        $this->assertFreshEnough($type, $context);

        $version = $this->prompts->for($type)->version;
        $key = $this->cache->key($type->value, $version, (string) config('ai.model'), $context->json);

        if (! $force && ($hit = $this->cache->find($key)) !== null) {
            return new AnalystResult($hit, true, null, 0);
        }

        $this->throttle($requestedBy);
        $reachedProvider = true;

        try {
            $generated = $this->generator->generate($type, $context, force: true);
        } catch (AiResponseInvalidException $e) {
            event(new AiGenerationFailed($type->value, 'invalid_response', count($e->errors).' validation problems after '.$e->attempts.' attempts'));

            throw $e;
        } catch (BudgetExceededException|UnpricedModelException $e) {
            $reachedProvider = false;   // refused up front: not a call, so not a click that counts against the throttle

            throw $e;
        } catch (AiProviderException $e) {
            event(new AiGenerationFailed($type->value, 'provider_error', $e::class.($e->status !== null ? " HTTP {$e->status}" : '')));

            throw $e;
        } finally {
            if ($reachedProvider) {
                $this->markAttempt($requestedBy);
            }
        }

        return $this->store($type, $period, $context, $generated, $requestedBy);
    }

    /** The entry the queue uses. Never throws for an expected refusal; returns what happened. Retryable provider errors DO propagate (the job retries them). */
    public function runScheduled(AiInsightType $type): ScheduledOutcome
    {
        $problem = $this->readiness->generationProblem();

        if ($problem !== null) {
            $this->readiness->logOnce($problem, $type->value);

            return ScheduledOutcome::skipped($problem);
        }

        if (! $this->chainFinishedToday()) {
            Log::notice("AI {$type->value} skipped: today's nightly metrics run has not completed yet.");

            return ScheduledOutcome::skipped('chain_not_finished');
        }

        try {
            $period = $this->periods->for($type);
            $result = $this->generate($type, $period);
        } catch (StaleDataException) {
            Log::warning("AI {$type->value} refused: data too old.");

            return ScheduledOutcome::skipped('stale_data');
        } catch (BudgetExceededException) {
            Log::warning("AI {$type->value} refused: monthly budget.");

            return ScheduledOutcome::skipped('budget_exceeded');
        } catch (UnpricedModelException) {
            Log::warning("AI {$type->value} refused: model has no price.");

            return ScheduledOutcome::skipped('unpriced_model');
        } catch (AiResponseInvalidException) {
            Log::warning("AI {$type->value} failed: the answer was invalid twice.");

            return ScheduledOutcome::skipped('invalid_response');
        } catch (ContextPiiException|ContextTooLargeException $e) {
            Log::warning("AI {$type->value} failed: context ".$e::class);

            return ScheduledOutcome::skipped('context_error');
        } catch (AiProviderException $e) {
            if ($e->retryable) {
                throw $e;
            }

            Log::warning("AI {$type->value} failed: provider ".$e::class);

            return ScheduledOutcome::skipped('provider_error');
        }

        return $result->fromCache ? ScheduledOutcome::cacheHit($result->insight->id) : ScheduledOutcome::generated($result->insight->id);
    }

    private function chainFinishedToday(): bool
    {
        $start = CarbonImmutable::now('Asia/Tehran')->setTime(max(0, min(23, (int) config('ai.generation.chain_start_hour', 3))), 0)->utc();

        return $this->metricRuns->fullRunCompletedSince($start);
    }

    private function assertFreshEnough(AiInsightType $type, BuiltContext $context): void
    {
        $age = json_decode($context->json, true)['data_quality']['data_age_days'] ?? null;
        $max = max(0, (int) config('ai.generation.max_data_age_days', 3));

        if ($age === null || (int) $age > $max) {
            event(new AiGenerationFailed($type->value, 'stale_data', $age === null ? 'no complete day of data' : "data is {$age} days old (limit {$max})"));

            throw new StaleDataException($age === null ? null : (int) $age, $max);
        }
    }

    /** The manual limit as the UI shows it: how many more manual generations this user may start in the current window. */
    public function manualAttemptsRemaining(int $requestedBy): int
    {
        return max(0, RateLimiter::remaining($this->throttleKey($requestedBy), max(1, (int) config('ai.manual.max_requests', 5))));
    }

    /** Refuses early (before a job is queued) with the same exception generate() would raise later. */
    public function assertManualAllowed(int $requestedBy): void
    {
        $this->throttle($requestedBy);
    }

    private function throttle(?int $requestedBy): void
    {
        if ($requestedBy === null) {
            return;
        }

        $key = $this->throttleKey($requestedBy);
        $max = max(1, (int) config('ai.manual.max_requests', 5));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ManualRequestThrottledException(max(1, RateLimiter::availableIn($key)));
        }
    }

    private function markAttempt(?int $requestedBy): void
    {
        if ($requestedBy !== null) {
            RateLimiter::hit($this->throttleKey($requestedBy), max(1, (int) config('ai.manual.per_minutes', 60)) * 60);
        }
    }

    private function throttleKey(int $requestedBy): string
    {
        return 'ai:manual:'.$requestedBy;
    }

    private function store(AiInsightType $type, AnalystPeriod $period, BuiltContext $context, GeneratedInsight $generated, ?int $requestedBy): AnalystResult
    {
        $insight = DB::transaction(function () use ($type, $period, $context, $generated, $requestedBy): AiInsight {
            // cache_key is UNIQUE (partial). A forced / expired regeneration has the same key: the old row keeps its history but gives the key up.
            AiInsight::query()->where('cache_key', $generated->cacheKey)->update(['cache_key' => null]);

            $row = AiInsight::query()->create([
                'type' => $type->value,
                'scope' => $period->scope,
                'period_start' => $period->start,
                'period_end' => $period->end,
                'payload' => $generated->payload,
                'provider' => $generated->responses[0]->provider ?? (string) config('ai.provider'),
                'model' => (string) ($generated->model ?? config('ai.model')),
                'prompt_version' => $generated->promptVersion,
                'input_tokens' => $generated->inputTokens,
                'output_tokens' => $generated->outputTokens,
                'cost_usd' => $generated->costUsd,
                'duration_ms' => $generated->durationMs,
                'cache_key' => $generated->cacheKey,
                'requested_by' => $requestedBy,
            ]);

            foreach ($context->toolResults as $tool) {
                AiToolCall::query()->create([
                    'insight_id' => $row->id,
                    'tool_name' => $tool->name,
                    'arguments' => $tool->arguments,
                    'result_size' => $tool->resultSize,
                    'duration_ms' => $tool->durationMs,
                    'error' => $tool->error,
                ]);
            }

            return $row;
        });

        event(new AiInsightGenerated($insight->id, $type->value, $period->scope));

        return new AnalystResult($insight, false, $generated, count($context->toolResults));
    }
}
