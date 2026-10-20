<?php

declare(strict_types=1);

namespace App\Modules\Ai\Dto;

use App\Modules\Ai\Enums\BudgetState;

/** Read-only snapshot for the UI (P7-09). Percentages are display values (0-100, 1 decimal), never used to enforce anything. */
final readonly class BudgetStatus
{
    public function __construct(
        public string $month,
        public string $usedUsd,
        public string $capUsd,
        public float $percentUsd,
        public int $usedTokens,
        public ?int $capTokens,
        public ?float $percentTokens,
        public int $requests,
        public BudgetState $state,
        public ?string $lastUsageDate,
    ) {}
}
