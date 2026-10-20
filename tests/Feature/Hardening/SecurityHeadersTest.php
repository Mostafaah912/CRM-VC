<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Vite;

it('sends the hardening headers on a web response', function () {
    $response = $this->get(route('login'));

    $response->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->not->toContain("'unsafe-eval'")
        ->and($csp)->not->toMatch("/script-src[^;]*'unsafe-inline'/");
});

it('uses a fresh script nonce per request and puts it on the inline blocks', function () {
    $nonce = fn () => preg_match("/'nonce-([^']+)'/", (string) $this->get(route('login'))->headers->get('Content-Security-Policy'), $m) ? $m[1] : null;

    $first = $nonce();
    $second = $nonce();

    expect($first)->not->toBeNull()->and($second)->not->toBe($first);

    $html = $this->get(route('login'))->getContent();
    expect($html)->toContain('nonce="'.Vite::cspNonce().'"');
});

it('sends HSTS only in production', function () {
    $this->get(route('login'))->assertHeaderMissing('Strict-Transport-Security');

    $this->app['env'] = 'production';
    config(['app.debug' => false]);

    $this->get(route('login'))->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
