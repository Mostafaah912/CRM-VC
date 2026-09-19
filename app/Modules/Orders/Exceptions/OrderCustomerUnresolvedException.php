<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use RuntimeException;

/**
 * The order has no usable phone, so it cannot be attached to a customer. PRD §08 says such an order is stored
 * with needs_review, but orders.customer_id is NOT NULL and an order has no review flag, so nothing can be
 * written: the service refuses and writes nothing, leaving the decision to the caller. The message names the
 * order only — never the phone — and does not chain the original exception (its text contains the number).
 */
final class OrderCustomerUnresolvedException extends RuntimeException
{
    public function __construct(public readonly int $wooOrderId)
    {
        parent::__construct("Woo order {$wooOrderId} has no usable customer phone and cannot be attached to a customer.");
    }
}
