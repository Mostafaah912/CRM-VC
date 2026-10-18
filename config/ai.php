<?php

/*
| AI Analyst provider layer (P7-02, PRD §19). Keys come from the environment only — never commit them.
| The model name is never hardcoded in code: it is AI_MODEL, nothing else.
|
| Provider: Gemini is the project owner's Phase-1 choice (ARCHITECTURE.md, P7-01); anthropic/openai drivers
| exist per PRD §25's "3 providers" but are simple and unverified against the live APIs.
*/
return [
    // `?:` not an env() default: a copied .env.example has these keys present-but-empty, which env() returns as ''.
    // P7-08: master switch. OFF by default — nothing calls a provider until a human turns this on (jobs become silent no-ops).
    'enabled' => filter_var(env('AI_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'provider' => env('AI_PROVIDER') ?: 'gemini',
    'model' => env('AI_MODEL'),

    'timeout' => (int) (env('AI_TIMEOUT') ?: 60),
    // Extra attempts after the first, only for transient failures (429 / 5xx / timeout).
    'max_retries' => (int) (env('AI_MAX_RETRIES') === null || env('AI_MAX_RETRIES') === '' ? 2 : env('AI_MAX_RETRIES')),
    'retry_backoff_seconds' => [2, 8, 30],

    'providers' => [
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => 'https://api.anthropic.com/v1',
            'version' => '2023-06-01',
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => 'https://api.openai.com/v1',
        ],
    ],

    /*
    | Analyst tools (P7-04). Every tool is read-only and aggregate-only; these are the shared ceilings.
    | small_group_min: a group of fewer customers is never shown on its own (PRD sets no threshold; 5 is the project default).
    */
    'context' => [
        // AI_MAX_CONTEXT_KB (PRD §19: max_context_kb 24) — the final JSON never exceeds this many KiB.
        'max_kb' => (float) (env('AI_MAX_CONTEXT_KB') ?: 24),
        // Data older than this many days (complete days behind yesterday, Asia/Tehran) is flagged stale_data.
        'stale_after_days' => (int) (env('AI_CONTEXT_STALE_AFTER_DAYS') ?: 1),
        // Rough token estimate = bytes / this. Display/log only (BudgetGuard uses its own conservative 1 token per byte).
        'bytes_per_token' => 3.0,
        // Extra strings that must never appear in a context (tests put fixture names here); empty by default.
        'pii_denylist' => [],
    ],

    /*
    | Scheduled generation (P7-08, PRD §22: daily 07:00 GenerateDailyBriefJob, Sat 07:30 GenerateWeeklyReviewJob, Asia/Tehran, queue `ai`).
    | These are separate scheduler entries, NOT part of hm:nightly-chain (a Bus::chain stops at the first failure; an AI failure must
    | never break the data chain). `max_data_age_days`: older data is not worth paying for — generation is refused and an alert raised.
    | `chain_start_hour`: a scheduled run first requires today's full metrics run (started at/after this Tehran hour) to have completed.
    | `weekly_day`: 0 = Sunday ... 6 = Saturday (the Jalali week starts on Saturday, so Saturday reviews the week that just ended Friday).
    */
    'generation' => [
        'max_data_age_days' => (int) (env('AI_MAX_DATA_AGE_DAYS') ?: 3),
        'daily_at' => env('AI_DAILY_AT') ?: '07:00',
        'weekly_at' => env('AI_WEEKLY_AT') ?: '07:30',
        'weekly_day' => env('AI_WEEKLY_DAY') === null || env('AI_WEEKLY_DAY') === '' ? 6 : (int) env('AI_WEEKLY_DAY'),
        'chain_start_hour' => 3,
    ],

    /*
    | Manual (UI/CLI-with-a-user) generation throttle, per requester: only calls that actually reach the provider count,
    | a cache hit is free. Stops repeated clicks from burning the monthly budget.
    */
    'manual' => [
        'max_requests' => (int) (env('AI_MANUAL_MAX_PER_HOUR') ?: 5),
        'per_minutes' => 60,
    ],

    /*
    | Analyst request (P7-07). max_output_tokens is deliberately generous (Persian text is token-heavy); the budget guard prices
    | the worst case from it, so lowering it also lowers the per-call worst-case spend.
    */
    'analyst' => [
        'max_output_tokens' => 3000,
        'temperature' => 0.2,
    ],

    /*
    | ResponseCache (P7-07): an insight whose cache_key (type + prompt version + model + context hash) already exists in ai_insights
    | and is younger than this is reused without calling the provider or spending budget. New data changes the context hash, hence the key.
    */
    'cache' => [
        'ttl_minutes' => 1440,
    ],

    'tools' => [
        'max_rows' => 25,
        'max_result_bytes' => 6144,
        'max_string_length' => 64,
        'small_group_min' => 5,
    ],

    /*
    | AnomalyDetector (P7-05): pure statistics over daily_metrics, no LLM, no network. PRD §19 names no metrics or thresholds,
    | so every number below is a documented project default (reasons in docs/architecture/sprint-7.md).
    |
    | A day is compared with the SAME WEEKDAY of the previous `window_days` (Iran's Thu/Fri differ from the rest of the week).
    | Robust z = (x - median) / sigma, sigma = max(1.4826 * MAD, min_sigma_fraction * |median|) — the floor is what stops a
    | zero MAD from inventing an infinite z. A day is an anomaly only if ALL hold: |z| >= z.info, relative change >=
    | min_relative_change, absolute change >= the metric's min_abs, and the baseline is big enough (min_baseline_orders).
    | `known_events`: optional [['name' => 'nowruz', 'from' => '2027-03-18', 'to' => '2027-03-30']] (Gregorian ISO, inclusive).
    | An anomaly inside one is KEPT and labelled `known_event`, never dropped. Empty by default: nothing is hardcoded.
    */
    'anomaly' => [
        'window_days' => 56,
        'min_samples' => 4,
        'min_baseline_orders' => 5,
        'min_relative_change' => 0.30,
        'min_sigma_fraction' => 0.10,
        'z' => ['info' => 3.5, 'warning' => 5.0, 'critical' => 8.0],
        'max_lookback_days' => 1100,
        'metrics' => [
            'orders_count' => ['min_abs' => 3],
            'net_revenue' => ['min_abs' => 1_000_000],
            'aov' => ['min_abs' => 100_000],
            'customers_new' => ['min_abs' => 2],
            'refund_rate' => ['min_abs' => 0.05],
            'repeat_share' => ['min_abs' => 0.10],
        ],
        'known_events' => [],
    ],

    /*
    | Monthly budget (PRD §19) — a HARD stop, not a warning: the guard refuses a call before contacting the provider
    | when month-to-date + in-flight reservations + this call's worst case would pass a cap. The month is the Gregorian
    | calendar month in Asia/Tehran. Defaults are deliberately SMALL; an unparseable value means "cap 0" (everything
    | refused), never "unlimited". `monthly_tokens` (input + output) is an optional second cap, independent of prices.
    | `allow_unpriced` exists for tests only: with it false, a model without a price is refused (fail-closed).
    */
    'budget' => [
        'monthly_usd' => env('AI_BUDGET_MONTHLY_USD') ?: '5',
        'warn_at' => env('AI_BUDGET_WARN_AT') ?: '0.8',
        'monthly_tokens' => env('AI_BUDGET_MONTHLY_TOKENS') ?: null,
        'allow_unpriced' => false,
        // Hidden "thinking" tokens are billed as output but are NOT counted by max_output_tokens on some models.
        // The worst case adds this many output tokens per call. Conservative default; tune down once real usage is seen.
        'reasoning_margin_tokens' => env('AI_REASONING_MARGIN_TOKENS') === null || env('AI_REASONING_MARGIN_TOKENS') === '' ? 4096 : (int) env('AI_REASONING_MARGIN_TOKENS'),
        'reservation_ttl_seconds' => 300,
        'lock_seconds' => 10,
    ],

    /*
    | USD per 1,000,000 tokens, as decimal STRINGS (never floats). ⚠ ESTIMATES MUST BE VERIFIED: every price is
    | 0 until someone copies the real number from the provider's pricing page — a guessed price would make
    | BudgetGuard (P7-03) silently wrong, and 0 makes that loud instead (AiCostCalculator logs a warning
    | for every unpriced call). Add one entry per model id in use: 'model-id' => ['input' => '0.00', 'output' => '0.00'].
    */
    'pricing' => [
        'default' => ['input' => '0', 'output' => '0'],
        'models' => [
            // USD per 1M tokens. Introductory price, valid through 2026-12-31. From 2027-01-01 Google's official pricing page
            // lists 1.50 input / 7.50 output — change both numbers on that date, or BudgetGuard will under-count spend by half.
            'gemini-3.8-flash' => ['input' => '0.75', 'output' => '3.75'],
        ],
    ],
];
