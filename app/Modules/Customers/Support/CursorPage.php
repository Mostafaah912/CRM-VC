<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

/**
 * One page of a cursor-paged list (orders, products, notes) as the browser receives it: the rows already shaped by their service,
 * the cursor for the next page (null on the last), has_more, and — only where the endpoint promises it — the total.
 *
 * @template TRow of array<string, mixed>
 */
final readonly class CursorPage
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 50;

    /** @param list<TRow> $rows */
    public function __construct(
        private array $rows,
        private ?string $nextCursor,
        private ?int $totalCount = null,
    ) {}

    /** @return array{data: list<TRow>, next_cursor: string|null, has_more: bool}|array{data: list<TRow>, next_cursor: string|null, has_more: bool, total_count: int} */
    public function toArray(): array
    {
        $page = ['data' => $this->rows, 'next_cursor' => $this->nextCursor, 'has_more' => $this->nextCursor !== null];

        return $this->totalCount === null ? $page : [...$page, 'total_count' => $this->totalCount];
    }
}
