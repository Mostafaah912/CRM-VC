<?php

declare(strict_types=1);

use App\Modules\Sync\Enums\WebhookVerdict;
use App\Modules\Sync\Services\WooWebhookVerifier;
use Tests\Support\WooWebhookSignature;

/*
| P2-09 authenticity: secret configured, source IP allowed (when a list is set), HMAC-SHA256 of the RAW body equal to
| the base64 signature Woo sent. Every failing check is a distinct verdict (for the log) that the HTTP layer turns into
| the same 401. Fails closed: no secret means nothing is authentic.
*/

const VERIFIER_SECRET = 'whsec-verifier-secret';

beforeEach(function () {
    config(['woo.webhook_secret' => VERIFIER_SECRET, 'woo.webhook_allowed_ips' => '']);
});

function verifierService(): WooWebhookVerifier
{
    return app(WooWebhookVerifier::class);
}

function verifierSignature(string $body, string $secret = VERIFIER_SECRET): string
{
    return WooWebhookSignature::for($body, $secret);
}

it('accepts the base64 HMAC-SHA256 of the raw body made with the configured secret', function () {
    $body = '{"id":5001,"note":"سفارش"}';

    expect(verifierService()->verify($body, verifierSignature($body), '203.0.113.10'))->toBe(WebhookVerdict::Authentic);
});

it('rejects a signature over a different body, a different secret, or none', function (?string $signature) {
    expect(verifierService()->verify('{"id":5001}', $signature, '203.0.113.10'))->toBe(WebhookVerdict::SignatureInvalid);
})->with([
    'another body' => [verifierSignature('{"id":5002}')],
    'another secret' => [verifierSignature('{"id":5001}', 'not-the-secret')],
    'missing' => [null],
    'empty' => [''],
    'garbage' => ['not-a-signature'],
    'the hex digest of the right HMAC' => [hash_hmac('sha256', '{"id":5001}', VERIFIER_SECRET)],
    'the right value with a space added' => [verifierSignature('{"id":5001}').' '],
    'the right value truncated' => [substr(verifierSignature('{"id":5001}'), 0, -1)],
]);

it('signs the exact bytes: a re-encoded body does not verify', function () {
    $sent = '{"id":5001,"a":"b"}';
    $reencoded = json_encode(json_decode($sent, true), JSON_PRETTY_PRINT);

    expect(verifierService()->verify($reencoded, verifierSignature($sent), null))->toBe(WebhookVerdict::SignatureInvalid);
});

it('fails closed without a secret, even for a signature made with an empty key', function (mixed $secret) {
    config(['woo.webhook_secret' => $secret]);
    $body = '{"id":5001}';

    expect(verifierService()->verify($body, verifierSignature($body, ''), '203.0.113.10'))->toBe(WebhookVerdict::SecretMissing)
        ->and(verifierService()->verify($body, verifierSignature($body), '203.0.113.10'))->toBe(WebhookVerdict::SecretMissing);
})->with(['null' => [null], 'empty' => ['']]);

it('does not check the source IP when the allowlist is empty or blank', function (mixed $list) {
    config(['woo.webhook_allowed_ips' => $list]);

    expect(verifierService()->verify('{}', verifierSignature('{}'), '198.51.100.99'))->toBe(WebhookVerdict::Authentic)
        ->and(verifierService()->verify('{}', verifierSignature('{}'), null))->toBe(WebhookVerdict::Authentic);
})->with([
    'empty string' => [''],
    'null' => [null],
    'blank entries' => [' , ,, '],
    'empty array' => [[]],
]);

it('allows only listed IPs once an allowlist is set: single addresses, CIDR ranges, IPv6, a comma list or an array', function (string $ip, mixed $list, WebhookVerdict $expected) {
    config(['woo.webhook_allowed_ips' => $list]);

    expect(verifierService()->verify('{}', verifierSignature('{}'), $ip))->toBe($expected);
})->with([
    'listed address' => ['203.0.113.10', '203.0.113.10', WebhookVerdict::Authentic],
    'listed among several, with spaces' => ['203.0.113.10', '198.51.100.7 , 203.0.113.10', WebhookVerdict::Authentic],
    'inside a CIDR range' => ['203.0.113.77', '203.0.113.0/24', WebhookVerdict::Authentic],
    'outside the CIDR range' => ['203.0.114.1', '203.0.113.0/24', WebhookVerdict::IpDenied],
    'IPv6 inside a range' => ['2001:db8::1', '2001:db8::/32', WebhookVerdict::Authentic],
    'IPv6 outside' => ['2001:db9::1', '2001:db8::/32', WebhookVerdict::IpDenied],
    'not listed' => ['203.0.113.11', '203.0.113.10', WebhookVerdict::IpDenied],
    'an array' => ['203.0.113.10', ['198.51.100.7', '203.0.113.10'], WebhookVerdict::Authentic],
    'a list of garbage denies everyone' => ['203.0.113.10', 'not-an-ip, 300.1.1.1', WebhookVerdict::IpDenied],
]);

it('denies an unknown source IP when an allowlist is set', function () {
    config(['woo.webhook_allowed_ips' => '203.0.113.10']);

    expect(verifierService()->verify('{}', verifierSignature('{}'), null))->toBe(WebhookVerdict::IpDenied);
});

it('checks the IP even when the signature is right, and reports the secret before either', function () {
    config(['woo.webhook_allowed_ips' => '203.0.113.10']);

    expect(verifierService()->verify('{}', verifierSignature('{}'), '198.51.100.1'))->toBe(WebhookVerdict::IpDenied);

    config(['woo.webhook_secret' => '']);

    expect(verifierService()->verify('{}', verifierSignature('{}'), '198.51.100.1'))->toBe(WebhookVerdict::SecretMissing);
});
