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

    // P8-03: staging must not receive production webhooks. Defaults OFF when APP_ENV=staging; enable there only with its OWN secret
    // (WOO_WEBHOOK_SECRET) registered on a separate Woo webhook. Everywhere else it defaults ON.
    'webhook_enabled' => filter_var(env('WOO_WEBHOOK_ENABLED', env('APP_ENV') !== 'staging'), FILTER_VALIDATE_BOOLEAN),

    // P8-03g (measured on staging, 70 requests): Woo answers any request in 2.3-4.9 s (p90 <= 4.9 s), 20 s is four times that. A request is
    // therefore bounded by (1 + max_retries) x timeout + the backoff ladder = 4 x 20 + 40 = 120 s, down from 5 x 30 + 160 = 310 s (PRD §10 said
    // 4 retries at 2/8/30/120 s): one stalled request can no longer eat the time budget of a whole job. A 429 Retry-After still wins over the ladder.
    'timeout' => 20,
    'per_page' => 50,

    'max_retries' => 3,
    'retry_backoff_seconds' => [2, 8, 30],

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

    // `taroff-4` = «ارسال با آنباکس» (shipped via Unbox): a custom status of this store that IS a real, realized sale (product-owner
    // decision, P7-14). Slugs are stored exactly as Woo's REST API returns them — no `wc-` prefix (orders.status holds `taroff-4`).
    // Changing this list changes `is_realized` only for orders written AFTER the change: run `php artisan hm:orders-reclassify` (idempotent,
    // local only) and then a full metrics recompute.
    'realized_statuses' => ['processing', 'completed', 'taroff-4'],
    'excluded_statuses' => ['pending', 'on-hold', 'cancelled', 'failed', 'trash'],

    // Persian display labels for order statuses (a store-defined set: unknown slugs are shown as stored). Used by the AI context's definitions
    // and the analyst screen's help text; the React label map (resources/js/lib/customer-labels.ts) mirrors it.
    'status_labels' => [
        'processing' => 'در حال پردازش',
        'completed' => 'تکمیل‌شده',
        'taroff-4' => 'ارسال با آنباکس',
    ],

    'webhook_secret' => env('WOO_WEBHOOK_SECRET'),
    'webhook_allowed_ips' => env('WOO_WEBHOOK_ALLOWED_IPS', ''),

    // P6-10: how long a sync_logs row is kept (PruneLogsJob). PRD names the job but never a retention window;
    // 30 days matches PRD §21's own backup retention (a reasoned default, not a PRD number).
    'sync_log_retention_days' => 30,
];
