<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Backup;

final readonly class BackupResult
{
    public function __construct(
        public string $file,
        public int $bytes,
        public string $sha256,
        public bool $weekly,
        public int $pruned,
        public int $seconds,
    ) {}
}
