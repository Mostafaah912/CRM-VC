<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\Ai\Events\AiBudgetExceeded;
use App\Modules\Ai\Events\AiBudgetWarning;
use App\Modules\Core\Enums\AlertKind;
use App\Modules\Core\Services\AlertService;

/**
 * Turns the Ai module's budget events into the PRD §22 `AiBudgetThreshold` alert through the existing AlertService.
 * Lives in app/Support (same reasoning as HealthCheckService): Ai may reach Core only through a public Service or
 * Event, and AlertKind is a Core enum. Soft warning and hard stop use separate dedupe scopes: the 60-minute AlertService window must never let a warning swallow the "budget is gone" alert. The "once per month" decision is made in the Ai module and arrives as event data.
 */
final class AiBudgetAlerts
{
    public function __construct(private readonly AlertService $alerts) {}

    public function onWarning(AiBudgetWarning $event): void
    {
        $this->alerts->critical(AlertKind::AiBudgetThreshold, "AI spend reached {$event->warnAt} of the monthly budget", [
            'month' => $event->month, 'used_usd' => $event->usedUsd, 'cap_usd' => $event->capUsd,
            'used_tokens' => $event->usedTokens, 'cap_tokens' => $event->capTokens,
        ], dedupeScope: 'warn');
    }

    public function onExceeded(AiBudgetExceeded $event): void
    {
        if (! $event->firstThisMonth) {
            return;
        }

        $this->alerts->critical(AlertKind::AiBudgetThreshold, 'AI monthly budget exhausted — calls are refused until next month', [
            'month' => $event->month, 'reason' => $event->reason, 'used_usd' => $event->usedUsd, 'cap_usd' => $event->capUsd,
        ], dedupeScope: 'blocked');
    }
}
