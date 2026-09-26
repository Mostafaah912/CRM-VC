<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Segment preview statement timeout
    |--------------------------------------------------------------------------
    |
    | PRD §17: "preview: compile->count() with statement_timeout = 5s". Kept
    | overridable via config (not a class constant, unlike RuleValidator's
    | limits) specifically so tests can force a near-instant timeout without
    | needing a slow query — a genuine testability need, not a store-tunable
    | business number like the RFM/churn thresholds elsewhere in this file's
    | siblings.
    |
    */

    'preview_timeout_ms' => 5000,
];
