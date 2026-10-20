<?php

declare(strict_types=1);

namespace App\Modules\Ai\Events;

/** Raised once per Tehran month, the first time spend crosses `warn_at` of a cap. Numbers only. */
final readonly class AiBudgetWarning
{
    public function __construct(
        public string $month,
        public string $warnAt,
        public string $usedUsd,
        public string $capUsd,
        public int $usedTokens,
        public ?int $capTokens,
    ) {}
}
