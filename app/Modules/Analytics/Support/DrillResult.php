<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

/**
 * What `DrillService::rows()` (P6-07) found for one dashboard widget: a small, PII-minimal table —
 * ids and numbers only, never a name or phone (same rule `RfmPageService::topChampions()` already
 * applies, CLAUDE.md §6/§7's "no PII leaves an aggregate view"). `truncated` is true when more rows
 * exist than `rows` carries (`DrillService::JSON_LIMIT`) — the full set is only ever available through
 * the audited CSV export, never a bigger JSON payload.
 */
final readonly class DrillResult
{
    /**
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public bool $truncated,
    ) {}
}
