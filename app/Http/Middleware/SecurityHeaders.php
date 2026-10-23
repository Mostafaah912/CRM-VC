<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * P8-01 / PRD §21: HSTS (production only), CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy.
 *
 * The CSP is nonce-based for scripts (the two inline blocks in app.blade.php carry the nonce via
 * Vite::cspNonce(); Vite adds it to its own tags). Inertia's page payload is a non-executable
 * application/json script, so it needs no allowance. Styles keep 'unsafe-inline' because Inertia/Radix
 * set inline style attributes, which a nonce cannot cover. With the Vite dev server running (public/hot),
 * its origin is added so `npm run dev` keeps working; that never applies to a production build.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(16));
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if (app()->environment('staging')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow'); // P8-03: a staging copy must never be indexed
        }

        if (app()->isProduction()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age='.(int) config('security.headers.hsts_max_age').'; includeSubDomains',
            );
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        $dev = $this->viteDevOrigin();
        $devWs = $dev === null ? '' : ' '.Str::replaceFirst('http', 'ws', $dev);
        $dev = $dev === null ? '' : ' '.$dev;

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$dev}",
            "style-src 'self' 'unsafe-inline'{$dev}",
            "img-src 'self' data: blob:",
            "font-src 'self' data:{$dev}",
            "connect-src 'self'{$dev}{$devWs}",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    private function viteDevOrigin(): ?string
    {
        $hot = public_path('hot');

        if (app()->isProduction() || ! is_file($hot)) {
            return null;
        }

        $url = trim((string) file_get_contents($hot));
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
