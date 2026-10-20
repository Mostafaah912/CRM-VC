<?php

declare(strict_types=1);

namespace App\Modules\Ai\Events;

/** A generation was refused or failed. `reason`: stale_data | invalid_response | provider_error | context_error. No model text, no PII. */
final readonly class AiGenerationFailed
{
    public function __construct(public string $type, public string $reason, public string $detail) {}
}
