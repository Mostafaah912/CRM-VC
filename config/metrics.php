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

    // PRD §11 low-sample guard: used instead of the real p50/p75/p90 when sample_size < 200.
    'fallback_percentiles' => [
        'p50' => 60,
        'p75' => 120,
        'p90' => 210,
    ],

    'clv' => [
        'min_orders_for_estimate' => 2,
        'low_confidence_threshold' => 3,
        'high_confidence_threshold' => 6,
    ],
];
