<?php

declare(strict_types=1);

namespace App\Modules\Ai\Events;

/** PRD §07: raised on every hard stop. Carries numbers only — never a prompt. */
final readonly class AiBudgetExceeded
{
    public function __construct(
        public string $month,
        public string $reason,
        public string $usedUsd,
        public string $capUsd,
        public int $usedTokens,
        public ?int $capTokens,
        public bool $firstThisMonth,
    ) {}
}
