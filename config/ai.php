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
        'models' => [],
    ],
];
