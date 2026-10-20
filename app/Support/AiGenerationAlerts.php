<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\Ai\Events\AiGenerationFailed;
use App\Modules\Core\Enums\AlertKind;
use App\Modules\Core\Services\AlertService;

/**
 * Turns the Ai module's AiGenerationFailed event into an AlertService alert (same pattern and reasoning as AiBudgetAlerts: AlertKind is a
 * Core enum, so the Ai module raises an Event and app/Support reacts). One dedupe window per reason, so "stale data" never hides
 * "invalid answer". The message carries a reason code and a short safe detail — never model text, never data.
 */
final class AiGenerationAlerts
{
    public function __construct(private readonly AlertService $alerts) {}

    public function onFailed(AiGenerationFailed $event): void
    {
        $this->alerts->critical(AlertKind::AiGenerationFailure, "AI {$event->type} generation failed: {$event->reason}", [
            'type' => $event->type, 'reason' => $event->reason, 'detail' => $event->detail,
        ], dedupeScope: $event->type.':'.$event->reason);
    }
}
