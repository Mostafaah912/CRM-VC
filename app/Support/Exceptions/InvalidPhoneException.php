<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use RuntimeException;

class InvalidPhoneException extends RuntimeException
{
    public static function empty(): self
    {
        return new self('Phone number is empty.');
    }

    public static function tooShort(string $raw): self
    {
        return new self("Phone number [{$raw}] has fewer than 10 significant digits.");
    }

    public static function notMobile(string $raw): self
    {
        return new self("Phone number [{$raw}] is not a valid Iranian mobile number (must start with 9 after the country/trunk prefix).");
    }
}
