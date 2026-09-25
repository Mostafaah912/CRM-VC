<?php

/*
| Reverse proxies whose X-Forwarded-* headers are believed (read per request by Illuminate\Http\Middleware\TrustProxies).
| Comma-separated IPs/CIDRs, or `*`. Empty (the default) trusts nobody, so the client IP is the connecting address.
| It matters for the Woo webhook's IP allowlist (woo.webhook_allowed_ips) when the app sits behind a proxy.
*/
return [
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
