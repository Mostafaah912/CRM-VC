<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Enums\AiDriver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * "Is it safe and possible to call the provider?" — in ONE place, so jobs, commands and (P7-09) the UI agree.
 * callProblem(): provider known, API key present, model set and PRICED (fail-closed: a model with no price cannot be budgeted).
 * generationProblem(): additionally the AI_ENABLED switch (off by default). The check never reveals a key.
 */
final class AnalystReadiness
{
    public function __construct(private readonly AiCostCalculator $costs) {}

    public function callProblem(): ?string
    {
        $driver = AiDriver::tryFrom((string) config('ai.provider'));

        return match (true) {
            $driver === null => 'unknown_provider',
            trim((string) config("ai.providers.{$driver->value}.api_key")) === '' => 'missing_api_key',
            trim((string) config('ai.model')) === '' => 'missing_model',
            $this->costs->price((string) config('ai.model')) === null && config('ai.budget.allow_unpriced') !== true => 'unpriced_model',
            default => null,
        };
    }

    public function generationProblem(): ?string
    {
        return config('ai.enabled') === true ? $this->callProblem() : 'ai_disabled';
    }

    /** One plain sentence per code, naming the setting to fix. */
    public function describe(string $code): string
    {
        return match ($code) {
            'ai_disabled' => 'AI is switched off: set AI_ENABLED=true when you are ready (it is false by default).',
            'unknown_provider' => 'AI_PROVIDER is not one of gemini, anthropic, openai.',
            'missing_api_key' => 'The API key for the chosen provider is empty (GEMINI_API_KEY / ANTHROPIC_API_KEY / OPENAI_API_KEY).',
            'missing_model' => 'AI_MODEL is empty.',
            'unpriced_model' => "The model has no price: fill config('ai.pricing.models') for AI_MODEL (input and output USD per 1M tokens, both above 0).",
            default => $code,
        };
    }

    /** Logs the reason ONCE per day (jobs run every day; a permanent "off" must not flood the log). */
    public function logOnce(string $code, string $job): void
    {
        if (Cache::add("ai:not-ready:{$code}", true, now()->addDay())) {
            Log::notice("AI job {$job} skipped: {$code}. ".$this->describe($code));
        }
    }
}
