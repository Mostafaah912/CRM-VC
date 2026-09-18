<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The frozen cursor (PRD §10). Built once at job start and immutable afterwards:
 *   cursor_to   = now(), fixed for the whole job (truncated to whole seconds so the
 *                 value stored as the new cursor is exactly the boundary that was queried)
 *   cursor_from = stored cursor - overlap_minutes (null on the very first sync)
 * Paginating by `modified` while records change moves rows between pages; pinning
 * modified_before stops the window from sliding under the job.
 *
 * Advancing the stored cursor (only on 'completed') belongs to SyncService, P2-08.
 */
final readonly class SyncWindow
{
    public function __construct(
        public ?CarbonImmutable $modifiedAfter,
        public CarbonImmutable $modifiedBefore,
    ) {
        if ($modifiedAfter !== null && $modifiedAfter->greaterThanOrEqualTo($modifiedBefore)) {
            throw new InvalidArgumentException('SyncWindow lower bound must be before its upper bound.');
        }
    }

    public static function freeze(?CarbonInterface $cursorValue, ?int $overlapMinutes = null): self
    {
        $overlap = $overlapMinutes ?? (int) config('woo.overlap_minutes');

        return new self(
            $cursorValue === null ? null : CarbonImmutable::instance($cursorValue)->utc()->subMinutes($overlap),
            CarbonImmutable::now('UTC')->startOfSecond(),
        );
    }

    /**
     * Query parameters for a windowed list request. Timestamps are UTC and paired with
     * dates_are_gmt so Woo compares them to its GMT columns, not the store's local time.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = [];

        if ($this->modifiedAfter !== null) {
            $query['modified_after'] = $this->format($this->modifiedAfter);
        }

        return $query + [
            'modified_before' => $this->format($this->modifiedBefore),
            'orderby' => 'modified',
            'order' => 'asc',
            'dates_are_gmt' => 'true',
        ];
    }

    private function format(CarbonImmutable $moment): string
    {
        return $moment->utc()->format('Y-m-d\TH:i:s');
    }
}
