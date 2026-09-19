<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

use App\Modules\Sync\Enums\SyncStatus;
use App\Modules\Sync\Models\SyncJob;
use App\Support\TehranDateTime;

/**
 * @phpstan-type SyncRunShape array{started_at: string, entity: string, status: string, pages_processed: int, records_processed: int, finished_at: string|null, duration_seconds: int|null, error: string|null}
 *
 * One sync run as an operator reads it. Built from COLUMNS only, so the frozen window (cursor_from/cursor_to) and the run's
 * id and mode are never loaded, let alone sent. The stored error was scrubbed when SyncService wrote it and is passed through
 * as-is, and only for a failed run. The entity and status are read raw so a value this build does not know cannot break the page.
 */
final readonly class SyncRunRow
{
    /** The only columns of sync_jobs a page may select. */
    public const COLUMNS = ['entity', 'status', 'pages_processed', 'records_processed', 'started_at', 'finished_at', 'error'];

    public function __construct(
        public string $startedAt,
        public string $entity,
        public string $status,
        public int $pagesProcessed,
        public int $recordsProcessed,
        public ?string $finishedAt,
        public ?int $durationSeconds,
        public ?string $error,
    ) {}

    public static function fromModel(SyncJob $run): self
    {
        $status = (string) $run->getRawOriginal('status');

        return new self(
            TehranDateTime::format($run->started_at),
            (string) $run->getRawOriginal('entity'),
            $status,
            $run->pages_processed,
            $run->records_processed,
            TehranDateTime::formatOrNull($run->finished_at),
            $run->finished_at === null ? null : max(0, $run->finished_at->getTimestamp() - $run->started_at->getTimestamp()),
            $status === SyncStatus::Failed->value ? $run->error : null,
        );
    }

    /** @return SyncRunShape */
    public function toArray(): array
    {
        return [
            'started_at' => $this->startedAt,
            'entity' => $this->entity,
            'status' => $this->status,
            'pages_processed' => $this->pagesProcessed,
            'records_processed' => $this->recordsProcessed,
            'finished_at' => $this->finishedAt,
            'duration_seconds' => $this->durationSeconds,
            'error' => $this->error,
        ];
    }
}
