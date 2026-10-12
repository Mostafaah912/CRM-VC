<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Support\Decimal;

/**
 * config('ai.budget') validated once. Anything unparseable becomes the SAFE value (cap 0 = every call refused),
 * never "unlimited": a typo in .env must stop spending, not release it.
 */
final readonly class AiBudgetConfig
{
    /** @param numeric-string $capUsd
     *  @param numeric-string $warnAt ratio of the cap, 0 < x <= 1 */
    public function __construct(
        public string $capUsd,
        public string $warnAt,
        public ?int $capTokens,
        public int $reasoningMarginTokens,
        public bool $allowUnpriced,
        public int $reservationTtlSeconds,
        public int $lockSeconds,
    ) {}

    public static function fromConfig(): self
    {
        $cap = Decimal::of(config('ai.budget.monthly_usd'));
        $warn = Decimal::of(config('ai.budget.warn_at'), '0');
        $tokens = config('ai.budget.monthly_tokens');

        return new self(
            $cap,
            bccomp($warn, '0', 6) > 0 && bccomp($warn, '1', 6) <= 0 ? $warn : '0.8',
            is_numeric($tokens) && (int) $tokens > 0 ? (int) $tokens : null,
            max(0, (int) config('ai.budget.reasoning_margin_tokens')),
            config('ai.budget.allow_unpriced') === true,
            max(30, (int) config('ai.budget.reservation_ttl_seconds')),
            max(1, (int) config('ai.budget.lock_seconds')),
        );
    }
}
