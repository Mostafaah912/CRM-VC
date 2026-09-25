<?php

declare(strict_types=1);

namespace App\Modules\Sync\Exceptions;

/** An order in a currency other than the store's money unit (config woo.currency). Refused, never converted. */
final class WooCurrencyMismatchException extends WooException
{
    public function __construct(
        public readonly int $wooOrderId,
        public readonly string $currency,
        public readonly string $expected,
    ) {
        parent::__construct("Woo order {$wooOrderId} is in currency '{$currency}' but the store unit is '{$expected}'.");
    }
}
