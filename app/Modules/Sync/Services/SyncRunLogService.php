<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Enums\SyncStatus;
use App\Modules\Sync\Models\SyncJob;
use App\Modules\Sync\Support\SyncRunRow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @phpstan-import-type SyncRunShape from SyncRunRow
 *
 * P2-12, read-only: the sync-run history for the logs page — newest first, 25 to a page, optionally narrowed by status and by
 * entity. Rows are SyncRunRow arrays selected from SyncRunRow::COLUMNS, so no cursor, id or mode is ever read.
 */
final class SyncRunLogService
{
    private const PER_PAGE = 25;

    /** @return LengthAwarePaginator<int, SyncRunShape> */
    public function paginate(?SyncStatus $status, ?SyncEntity $entity): LengthAwarePaginator
    {
        return SyncJob::query()
            ->select(SyncRunRow::COLUMNS)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($entity !== null, fn ($query) => $query->where('entity', $entity))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (SyncJob $run): array => SyncRunRow::fromModel($run)->toArray());
    }

    /** @return array{statuses: list<string>, entities: list<string>} what the two filters accept */
    public function filterOptions(): array
    {
        return [
            'statuses' => array_map(fn (SyncStatus $status): string => $status->value, SyncStatus::cases()),
            'entities' => array_map(fn (SyncEntity $entity): string => $entity->value, SyncEntity::cases()),
        ];
    }
}
