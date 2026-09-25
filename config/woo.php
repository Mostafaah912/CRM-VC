<?php

use App\Support\JalaliDate;

/*
| WooCommerce connection + order-status classification (PRD §10).
|
| Credentials come from the environment only — never commit them (CLAUDE.md §6).
| `base_url` is the store root (e.g. https://shop.example.com); the client appends
| /wp-json/{version}. Basic auth is HTTPS-only and the key never goes in a query string.
|
| Statuses are store-defined Woo data: `is_realized` is derived from
| `realized_statuses` and nothing else — never from a string in code (CLAUDE.md §3).
| realized_statuses = processing + completed per the P0-00 verification (this store
| has no `shipped` status, ARCHITECTURE.md).
|
| Webhook (P2-09): `webhook_secret` signs every delivery (HMAC-SHA256, base64) — empty means every delivery is rejected.
| `webhook_allowed_ips` is a comma-separated list of IPs/CIDRs Woo may call from; EMPTY = the IP check is off (the
| signature is still mandatory) until the store's addresses are confirmed. Never commit either value.
*/
return [
    'base_url' => env('WOO_BASE_URL'),
    'key' => env('WOO_CONSUMER_KEY'),
    'secret' => env('WOO_CONSUMER_SECRET'),
    'version' => 'wc/v3',

    'timeout' => 30,
    'per_page' => 50,

    // Retry policy: PRD §10 — 4 retries at 2s, 8s, 30s, 120s (a 429 Retry-After wins over the ladder).
    'max_retries' => 4,
    'retry_backoff_seconds' => [2, 8, 30, 120],

    // Redis token bucket: capacity = this value, refilled continuously at value/60 per second.
    'rate_limit_per_minute' => 90,

    // cursor_from = stored cursor - overlap (PRD §10 frozen cursor).
    'overlap_minutes' => 10,

    // Where a FULL sync (hm:sync --full) starts reading: Mehr 1, 1403 — the first month Gate 1 reconciles — as a UTC
    // instant, derived from the Jalali calendar (never a hardcoded date). Treated like a cursor, so the window starts
    // overlap_minutes earlier.
    'sync_epoch' => JalaliDate::toGregorian(1403, 7, 1)->utc()->toIso8601String(),

    // Any run, full or incremental, stops after this many pages if Woo still has more: the run completes, the cursor
    // moves to the last processed order's modified time, and the next run continues from there (resumable). A value that
    // is not a positive whole number falls back to 500.
    'sync_max_pages_per_run' => 500,

    // The store's money unit (P0-00: IRT = Toman, no decimals). An order in any other currency is refused, never converted.
    'currency' => 'IRT',

    'realized_statuses' => ['processing', 'completed'],
    'excluded_statuses' => ['pending', 'on-hold', 'cancelled', 'failed', 'trash'],

    'webhook_secret' => env('WOO_WEBHOOK_SECRET'),
    'webhook_allowed_ips' => env('WOO_WEBHOOK_ALLOWED_IPS', ''),
];
