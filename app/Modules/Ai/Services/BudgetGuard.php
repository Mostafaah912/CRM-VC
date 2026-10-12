<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Events\AiBudgetExceeded;
use App\Modules\Ai\Events\AiBudgetWarning;
use App\Modules\Ai\Exceptions\BudgetExceededException;
use App\Modules\Ai\Exceptions\UnpricedModelException;
use App\Modules\Ai\Support\Decimal;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PRD §19 hard stop. reserve() runs BEFORE a provider is contacted and either returns a reservation id or throws.
 *
 * Concurrency: month-to-date spend (DB) plus every in-flight reservation (cache) plus THIS call's worst case is
 * compared with the cap inside one cache lock, and the new reservation is written inside that same lock — so two
 * concurrent calls near the cap cannot both pass. A reservation carries an expiry, so a crashed process cannot block
 * the budget forever. (A cache outage fails closed: if the lock cannot be taken the call is refused.)
 *
 * Worst case = (input estimate + max output tokens + reasoning margin) priced. The input estimate is 1 token per UTF-8 BYTE of
 * everything sent — a deliberate over-estimate (real tokenizers average several bytes per token), so the guard errs
 * toward refusing. The only way to overshoot is a provider billing more output than max_output_tokens (e.g. hidden
 * reasoning tokens beyond the margin) — recorded honestly afterwards, so the NEXT call is refused.
 */
final class BudgetGuard
{
    private const LOCK = 'ai:budget:lock';

    private const RESERVATIONS = 'ai:budget:reservations';

    public function __construct(
        private readonly AiUsageService $usage,
        private readonly AiCostCalculator $costs,
    ) {}

    /** @return string reservation id, to be passed to release() */
    public function reserve(AiRequest $request, string $model): string
    {
        $config = AiBudgetConfig::fromConfig();
        $price = $this->costs->price($model);

        if ($price === null && ! $config->allowUnpriced) {
            throw new UnpricedModelException(
                "Budget cannot be enforced: no price for model [{$model}] — fill config('ai.pricing.models.{$model}') (input and output USD per 1M tokens, both above 0).",
                $model,
            );
        }

        $estimatedIn = $this->estimateInputTokens($request);
        $worstOutput = $request->maxOutputTokens + $config->reasoningMarginTokens;
        $worstTokens = $estimatedIn + $worstOutput;
        $worstUsd = $price === null
            ? '0'
            : bcdiv(bcadd(bcmul((string) $estimatedIn, $price['input'], 10), bcmul((string) $worstOutput, $price['output'], 10), 10), '1000000', 10);

        try {
            return Cache::lock(self::LOCK, $config->lockSeconds)->block($config->lockSeconds, function () use ($config, $worstUsd, $worstTokens, $model): string {
                $totals = $this->usage->monthTotals();
                $reservations = $this->liveReservations();
                $reservedUsd = '0';
                $reservedTokens = 0;

                foreach ($reservations as $r) {
                    $reservedUsd = bcadd($reservedUsd, $r['usd'], 10);
                    $reservedTokens += $r['tokens'];
                }

                $reason = match (true) {
                    bccomp(bcadd(bcadd($totals['usd'], $reservedUsd, 10), $worstUsd, 10), $config->capUsd, 10) > 0 => 'usd',
                    $config->capTokens !== null && $config->capTokens < $totals['tokens'] + $reservedTokens + $worstTokens => 'tokens',
                    default => null,
                };

                if ($reason !== null) {
                    $this->refuse($config, $reason, $totals, $model);
                }

                $id = (string) Str::uuid();
                $reservations[$id] = ['usd' => $worstUsd, 'tokens' => $worstTokens, 'expires' => time() + $config->reservationTtlSeconds];
                Cache::put(self::RESERVATIONS, $reservations, now()->addHour());

                return $id;
            });
        } catch (LockTimeoutException) {
            throw new BudgetExceededException('Budget could not be verified (lock timeout) — refusing the call.', $model);
        }
    }

    /** Frees the reservation, whether the call succeeded (usage already recorded) or failed (nothing recorded). */
    public function release(string $reservationId): void
    {
        $config = AiBudgetConfig::fromConfig();

        try {
            Cache::lock(self::LOCK, $config->lockSeconds)->block($config->lockSeconds, function () use ($reservationId): void {
                $reservations = $this->liveReservations();
                unset($reservations[$reservationId]);
                Cache::put(self::RESERVATIONS, $reservations, now()->addHour());
            });
        } catch (LockTimeoutException) {
            // Expiry frees it anyway; never mask the call's own outcome.
            Log::warning('AI budget reservation could not be released; it will expire', ['ttl_seconds' => $config->reservationTtlSeconds]);
        }
    }

    /** Soft warning: AiBudgetWarning once per Tehran month (App\Support\AiBudgetAlerts turns it into the PRD §22 alert). */
    public function warnIfNeeded(): void
    {
        $config = AiBudgetConfig::fromConfig();
        $totals = $this->usage->monthTotals();
        $usdHit = bccomp($totals['usd'], bcmul($config->capUsd, $config->warnAt, 10), 10) >= 0 && bccomp($config->capUsd, '0', 10) > 0;
        $tokensHit = $config->capTokens !== null && $totals['tokens'] >= (int) floor($config->capTokens * (float) $config->warnAt);

        if (($usdHit || $tokensHit) && $this->oncePerMonth('warned')) {
            event(new AiBudgetWarning($this->usage->monthKey(), $config->warnAt, $totals['usd'], $config->capUsd, $totals['tokens'], $config->capTokens));
        }
    }

    /** @param array{usd: string, tokens: int, requests: int, last_date: string|null} $totals */
    private function refuse(AiBudgetConfig $config, string $reason, array $totals, string $model): never
    {
        $month = $this->usage->monthKey();

        Log::warning('AI call refused: monthly budget', ['month' => $month, 'reason' => $reason, 'used_usd' => $totals['usd'], 'cap_usd' => $config->capUsd]);
        event(new AiBudgetExceeded($month, $reason, $totals['usd'], $config->capUsd, $totals['tokens'], $config->capTokens, $this->oncePerMonth('blocked')));

        throw new BudgetExceededException(
            $reason === 'usd'
                ? "AI monthly USD budget would be exceeded (used {$totals['usd']} of {$config->capUsd}); the call was not made."
                : "AI monthly token budget would be exceeded (used {$totals['tokens']} of {$config->capTokens}); the call was not made.",
            $model,
        );
    }

    private function oncePerMonth(string $kind): bool
    {
        return Cache::add("ai:budget:{$kind}:".$this->usage->monthKey(), true, now()->addDays(40));
    }

    /** @return array<string, array{usd: numeric-string, tokens: int, expires: int}> */
    private function liveReservations(): array
    {
        $live = [];

        foreach ((array) Cache::get(self::RESERVATIONS, []) as $id => $r) {
            if (is_array($r) && ($r['expires'] ?? 0) > time()) {
                $live[(string) $id] = ['usd' => Decimal::of($r['usd'] ?? null), 'tokens' => (int) $r['tokens'], 'expires' => (int) $r['expires']];
            }
        }

        return $live;
    }

    private function estimateInputTokens(AiRequest $request): int
    {
        $bytes = strlen($request->systemPrompt);

        foreach ($request->messages as $m) {
            $bytes += strlen($m->content);

            foreach ($m->toolCalls as $call) {
                $bytes += strlen($call->name) + strlen((string) json_encode($call->arguments));
            }
        }

        foreach ($request->tools as $tool) {
            $bytes += strlen($tool->name) + strlen($tool->description) + strlen((string) json_encode($tool->parameters));
        }

        return $bytes + strlen((string) json_encode($request->responseSchema ?? []));
    }
}
