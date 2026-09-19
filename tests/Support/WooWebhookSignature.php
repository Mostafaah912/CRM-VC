<?php

declare(strict_types=1);

namespace Tests\Support;

/** How WooCommerce signs a webhook delivery: base64 of the raw HMAC-SHA256 of the raw body, keyed with the webhook secret. */
final class WooWebhookSignature
{
    public static function for(string $body, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $body, $secret, true));
    }
}
