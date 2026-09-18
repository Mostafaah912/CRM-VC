<?php

declare(strict_types=1);

namespace App\Modules\Sync\Exceptions;

/**
 * The request failed for good: a terminal status, or a retryable failure that outlived the retry budget.
 * `status` is null for connection failures and timeouts. Carries no URL query, header, or credential.
 */
final class WooRequestException extends WooException
{
    public function __construct(
        string $message,
        public readonly string $endpoint,
        public readonly ?int $status,
        public readonly int $attempts,
        public readonly bool $retryable,
        public readonly ?string $wooCode = null,
    ) {
        parent::__construct($message);
    }
}
