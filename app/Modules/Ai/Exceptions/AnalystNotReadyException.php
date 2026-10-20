<?php

declare(strict_types=1);

namespace App\Modules\Ai\Exceptions;

use RuntimeException;

/** AI is not ready to generate. `reason`: ai_disabled | missing_api_key | missing_model | unpriced_model | unknown_provider. Never carries a key. */
final class AnalystNotReadyException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
