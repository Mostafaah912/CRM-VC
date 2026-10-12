<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Dto\BudgetStatus;
use App\Modules\Ai\Enums\BudgetState;

/** Read-only budget snapshot for the UI (P7-09). Never reserves, records or alerts. */
final class BudgetStatusService
{
    public function __construct(private readonly AiUsageService $usage) {}

    public function status(): BudgetStatus
    {
        $config = AiBudgetConfig::fromConfig();
        $totals = $this->usage->monthTotals();
        $capPositive = bccomp($config->capUsd, '0', 10) > 0;
        $usdRatio = $capPositive ? bcdiv($totals['usd'], $config->capUsd, 10) : '1';
        $tokenRatio = $config->capTokens === null ? null : bcdiv((string) $totals['tokens'], (string) $config->capTokens, 10);

        $state = match (true) {
            ! $capPositive, bccomp($usdRatio, '1', 10) >= 0, $tokenRatio !== null && bccomp($tokenRatio, '1', 10) >= 0 => BudgetState::Blocked,
            bccomp($usdRatio, $config->warnAt, 10) >= 0, $tokenRatio !== null && bccomp($tokenRatio, $config->warnAt, 10) >= 0 => BudgetState::Warn,
            default => BudgetState::Ok,
        };

        return new BudgetStatus(
            $this->usage->monthKey(),
            $totals['usd'],
            $config->capUsd,
            round((float) $usdRatio * 100, 1),
            $totals['tokens'],
            $config->capTokens,
            $tokenRatio === null ? null : round((float) $tokenRatio * 100, 1),
            $totals['requests'],
            $state,
            $totals['last_date'],
        );
    }
}
