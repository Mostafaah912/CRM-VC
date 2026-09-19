<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use RuntimeException;

/**
 * A refund set cannot be mirrored without breaking an invariant: the order is not synced (refunds.order_id is a
 * foreign key, and inventing an order is not this service's job), or a Woo refund already belongs to another
 * order (woo_refund_id is UNIQUE and a Woo refund never moves). Nothing is written. `reason` is a stable code.
 */
final class RefundSyncException extends RuntimeException
{
    public const ORDER_NOT_FOUND = 'order_not_found';

    public const REFUND_UNDER_ANOTHER_ORDER = 'refund_under_another_order';

    private function __construct(
        public readonly string $reason,
        public readonly int $wooOrderId,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function orderNotFound(int $wooOrderId): self
    {
        return new self(self::ORDER_NOT_FOUND, $wooOrderId, "Woo order {$wooOrderId} is not synced, so its refunds cannot be attached.");
    }

    public static function refundUnderAnotherOrder(int $wooOrderId, int $wooRefundId): self
    {
        return new self(self::REFUND_UNDER_ANOTHER_ORDER, $wooOrderId, "Woo refund {$wooRefundId} is already stored under a different order than Woo order {$wooOrderId}.");
    }
}
