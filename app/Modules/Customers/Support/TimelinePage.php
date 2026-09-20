<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

/**
 * One page of a customer's timeline as the browser receives it. Built only by CustomerTimelineService, which has already
 * filtered each payload to the allowlist and formatted each date; this class adds nothing and reads nothing.
 *
 * @phpstan-type TimelineEventShape array{id: int, event_type: string, happened_at_jalali: string, happened_at_iso: string, payload: array<string, string|int|bool|null>|null}
 * @phpstan-type TimelinePageShape array{data: list<TimelineEventShape>, next_cursor: string|null, has_more: bool}
 */
final readonly class TimelinePage
{
    /** @param list<TimelineEventShape> $events newest first */
    public function __construct(
        private array $events,
        private ?string $nextCursor,
    ) {}

    /** @return TimelinePageShape */
    public function toArray(): array
    {
        return [
            'data' => $this->events,
            'next_cursor' => $this->nextCursor,
            'has_more' => $this->nextCursor !== null,
        ];
    }
}
