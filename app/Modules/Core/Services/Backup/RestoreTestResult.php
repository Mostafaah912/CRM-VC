<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Backup;

final readonly class RestoreTestResult
{
    /** @param  list<array{table: string, restored: int, live_at_backup: int, restored_max: string|null, live_max_at_backup: string|null, ok: bool}>  $tables */
    public function __construct(
        public string $file,
        public int $seconds,
        public array $tables,
    ) {}
}
