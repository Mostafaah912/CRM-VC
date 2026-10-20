<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Support\Decimal;
use Illuminate\Support\Facades\Log;

/** cost_usd from token counts and config('ai.pricing') — bcmath on decimal strings, 5 decimals, never a float. */
final class AiCostCalculator
{
    /**
     * The price used for $model, and where it came from — null when either side is missing or zero (unpriced).
     *
     * @return array{input: numeric-string, output: numeric-string, key: string}|null
     */
    public function price(string $model): ?array
    {
        $models = (array) config('ai.pricing.models', []);
        $fromModel = isset($models[$model]);
        $price = (array) ($fromModel ? $models[$model] : config('ai.pricing.default'));
        $in = Decimal::of($price['input'] ?? null);
        $out = Decimal::of($price['output'] ?? null);

        if (bccomp($in, '0', 10) <= 0 || bccomp($out, '0', 10) <= 0) {
            return null;
        }

        return ['input' => $in, 'output' => $out, 'key' => $fromModel ? "ai.pricing.models.{$model}" : 'ai.pricing.default'];
    }

    public function cost(string $provider, string $model, int $inputTokens, int $outputTokens): string
    {
        // Not dot-notation: model ids such as "x-1.5" contain dots.
        $price = (array) config('ai.pricing.models', []);
        $price = $price[$model] ?? config('ai.pricing.default');
        $price = (array) $price;
        $in = Decimal::of($price['input'] ?? null);
        $out = Decimal::of($price['output'] ?? null);

        if (bccomp($in, '0', 10) === 0 && bccomp($out, '0', 10) === 0) {
            Log::warning('AI call is unpriced: set config(ai.pricing) for this model or BudgetGuard cannot see its cost.', [
                'provider' => $provider,
                'model' => $model,
            ]);
        }

        $sum = bcadd(
            bcmul((string) $inputTokens, $in, 10),
            bcmul((string) $outputTokens, $out, 10),
            10,
        );

        // Half-up to the 5 decimals of ai_insights.cost_usd (bcdiv alone truncates).
        return bcadd(bcdiv($sum, '1000000', 10), '0.000005', 5);
    }
}
