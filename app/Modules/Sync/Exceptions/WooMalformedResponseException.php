<?php

declare(strict_types=1);

namespace App\Modules\Sync\Exceptions;

/** A 2xx answer that is not the JSON shape Woo documents. Never retried: the same answer would come back. */
final class WooMalformedResponseException extends WooException
{
    public function __construct(string $message, public readonly string $endpoint)
    {
        parent::__construct($message);
    }
}
