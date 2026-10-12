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
