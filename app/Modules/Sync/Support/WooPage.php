<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

/** One page of a Woo collection, as decoded JSON. Mapping to domain DTOs is P2-03, not here. */
final readonly class WooPage
{
    /**
     * @param  list<array<array-key, mixed>>  $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $totalPages,
        public ?int $total,
    ) {}

    /** PRD §10: stop when page > X-WP-TotalPages or the page is empty. */
    public function hasMore(): bool
    {
        return $this->items !== [] && $this->page < $this->totalPages;
    }
}
