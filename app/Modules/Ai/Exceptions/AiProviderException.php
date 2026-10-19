<?php

declare(strict_types=1);

namespace App\Modules\Ai\Exceptions;

use App\Modules\Ai\Dto\AiResponse;
use RuntimeException;

/**
 * Base of every provider failure. Messages are built from the provider name and a status only —
 * never the API key, the prompt, a response body or a transport error text (cURL echoes URLs).
 *
 * `billed`: when the provider DID answer (so tokens were consumed and charged) but the answer was unusable — cut off, blocked,
 * malformed — the real usage travels here as a text-less AiResponse, so BudgetedAiProvider can record the spend. null = no usage
 * known (timeout before any answer, 4xx/5xx), which is recorded as nothing.
 */
class AiProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly ?int $status = null,
        public readonly bool $retryable = false,
        public readonly int $attempts = 1,
        public readonly ?AiResponse $billed = null,
    ) {
        parent::__construct($message);
    }
}
