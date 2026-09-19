<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Modules\Sync\Enums\WebhookVerdict;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Is this delivery really from our Woo store? (PRD §10, CLAUDE.md §5, threat T4.) Three checks, in this order:
 *
 *  1. a secret is configured — with none, nothing is authentic (an empty HMAC key is trivially forgeable);
 *  2. the source IP is on `woo.webhook_allowed_ips` — an EMPTY list switches this check off (the signature stays
 *     mandatory); a non-empty list of only unusable entries denies everyone;
 *  3. the signature is base64(HMAC-SHA256(raw body, secret)), the exact form WooCommerce sends, compared in constant
 *     time. It is computed over the raw bytes, so a re-encoded body never verifies.
 *
 * Pure: no request object, no logging. The caller answers 401 for every failing verdict and logs only the reason.
 */
final class WooWebhookVerifier
{
    public function verify(string $rawBody, ?string $signature, ?string $ip): WebhookVerdict
    {
        $secret = (string) config('woo.webhook_secret');

        if ($secret === '') {
            return WebhookVerdict::SecretMissing;
        }

        if (! $this->ipAllowed($ip)) {
            return WebhookVerdict::IpDenied;
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return is_string($signature) && $signature !== '' && hash_equals($expected, $signature)
            ? WebhookVerdict::Authentic
            : WebhookVerdict::SignatureInvalid;
    }

    private function ipAllowed(?string $ip): bool
    {
        $allowed = $this->allowedIps();

        if ($allowed === []) {
            return true;
        }

        return $ip !== null && IpUtils::checkIp($ip, $allowed);
    }

    /** @return list<string> the configured IPs/CIDRs, from a comma-separated string or an array, blanks dropped */
    private function allowedIps(): array
    {
        $configured = config('woo.webhook_allowed_ips');
        $entries = is_array($configured) ? $configured : explode(',', (string) $configured);

        return array_values(array_filter(
            array_map(fn (mixed $entry): string => trim((string) $entry), $entries),
            fn (string $entry): bool => $entry !== '',
        ));
    }
}
