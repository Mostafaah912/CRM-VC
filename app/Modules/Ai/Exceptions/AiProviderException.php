<?php

declare(strict_types=1);

namespace App\Modules\Ai\Exceptions;

use RuntimeException;

/**
 * Base of every provider failure. Messages are built from the provider name and a status only —
 * never the API key, the prompt, a response body or a transport error text (cURL echoes URLs).
 */
class AiProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly ?int $status = null,
        public readonly bool $retryable = false,
        public readonly int $attempts = 1,
    ) {
        parent::__construct($message);
    }
}
