<?php

/*
| Order-status classification only (PRD §10). Statuses are store-defined Woo data:
| `is_realized` is derived from this list and nothing else — never from a string
| in code (CLAUDE.md §3). realized_statuses = processing + completed per the
| P0-00 verification (this store has no `shipped` status, ARCHITECTURE.md).
| P2-01 adds the connection/retry/webhook settings to this same file.
*/
return [
    'realized_statuses' => ['processing', 'completed'],
    'excluded_statuses' => ['pending', 'on-hold', 'cancelled', 'failed', 'trash'],
];
