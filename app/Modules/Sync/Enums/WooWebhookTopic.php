<?php

declare(strict_types=1);

namespace App\Modules\Sync\Enums;

/**
 * The Woo webhook topics this app routes (P2-09): order created, updated, deleted — nothing else (no refund, product or
 * coupon topic). Woo sends `order.created`; the `woocommerce.`-prefixed spelling maps to the same case. Exactly one
 * prefix is understood and the match is exact (case, whitespace): an unknown topic is simply not routed.
 */
enum WooWebhookTopic: string
{
    case OrderCreated = 'order.created';
    case OrderUpdated = 'order.updated';
    case OrderDeleted = 'order.deleted';

    private const ALTERNATIVE_PREFIX = 'woocommerce.';

    public static function fromHeader(?string $header): ?self
    {
        $topic = (string) $header;

        if (str_starts_with($topic, self::ALTERNATIVE_PREFIX)) {
            $topic = substr($topic, strlen(self::ALTERNATIVE_PREFIX));
        }

        return self::tryFrom($topic);
    }
}
