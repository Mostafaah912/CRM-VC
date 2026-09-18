<?php

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
| webhook_secret / webhook_allowed_ips arrive with P2-09, not here.
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

    'realized_statuses' => ['processing', 'completed'],
    'excluded_statuses' => ['pending', 'on-hold', 'cancelled', 'failed', 'trash'],
];
