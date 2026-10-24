<?php

declare(strict_types=1);

/*
| PRD §09/§11/§12. D2: shipping is excluded from `monetary` (the RFM "M" input) by default — a store
| that wants it included can flip this without a code or migration change.
*/
return [
    'include_shipping' => (bool) env('METRICS_INCLUDE_SHIPPING', false),
    'margin_rate' => (float) env('METRICS_MARGIN_RATE', 0.17),
    'horizon_years' => (float) env('METRICS_HORIZON_YEARS', 2.0),

    /*
    | P6-20, product-owner decision: 3 years of Toman inflation makes a lifetime-NTILE "M" compare a
    | 1403 order against a 1405 one at face value. 'recent_window' scores M from only the last
    | `window_days` days of realized orders instead, with cut-points from the window's own
    | distribution (RfmCalculator) rather than NTILE's equal-COUNT buckets. Default stays 'lifetime'
    | so GATE 2 (tests/fixtures/expected_metrics.json, frozen at DemoDataSeeder::AS_OF under the old
    | formula) and every existing RfmCalculator/Gate2 test keep passing unmodified; dev/prod .env sets
    | METRICS_MONETARY_MODE=recent_window explicitly.
    */
    'monetary' => [
        'mode' => env('METRICS_MONETARY_MODE', 'lifetime'),
        'window_days' => (int) env('METRICS_MONETARY_WINDOW_DAYS', 60),
    ],

    // PRD §11 low-sample guard: used instead of the real p50/p75/p90 when sample_size < 200.
    'fallback_percentiles' => [
        'p50' => 60,
        'p75' => 120,
        'p90' => 210,
    ],

    'clv' => [
        // P8 decision: clv_estimated is computed from DISTINCT PURCHASE DAYS (Asia/Tehran dates) — the cycle is the median gap between them,
        // never less than one day, and a customer needs at least `min_purchase_days` of them. Two orders minutes apart are one purchase.
        'min_purchase_days' => (int) env('METRICS_CLV_MIN_PURCHASE_DAYS', 2),
        // ... and the projection never exceeds this multiple of clv_historical (lifetime margin so far).
        'cap_multiple' => (float) env('METRICS_CLV_CAP_MULTIPLE', 3.0),
        'min_orders_for_estimate' => 2,
        'low_confidence_threshold' => 3,
        'high_confidence_threshold' => 6,
    ],
];
