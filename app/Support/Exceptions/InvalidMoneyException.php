<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use RuntimeException;

class InvalidMoneyException extends RuntimeException
{
    public static function forValue(string $raw): self
    {
        return new self("Value [{$raw}] is not a valid integer Toman amount. Money is never a float.");
    }
}
