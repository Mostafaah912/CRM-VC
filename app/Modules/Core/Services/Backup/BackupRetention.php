<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;

/** Daily copies live 30 days, weekly copies 12 weeks (PRD §21). Age comes from the file NAME (UTC minute), never from disk mtime. */
final class BackupRetention
{
    public function __construct(private readonly BackupSupport $support) {}

    /** @return int number of dump files deleted (their .sha256 goes with them) */
    public function prune(Filesystem $disk, CarbonImmutable $now): int
    {
        $deleted = 0;
        $deleted += $this->pruneFolder($disk, BackupSupport::DAILY, $now->subDays((int) config('backup.retention.daily_days')));
        $deleted += $this->pruneFolder($disk, BackupSupport::WEEKLY, $now->subWeeks((int) config('backup.retention.weekly_weeks')));

        return $deleted;
    }

    private function pruneFolder(Filesystem $disk, string $folder, CarbonImmutable $cutoff): int
    {
        $deleted = 0;

        foreach ($this->support->listDumps($disk, $folder) as $file) {
            $at = $this->support->timestampOf($file);

            if ($at !== null && $at->lessThan($cutoff)) {
                $disk->delete([$file, $file.'.sha256']);
                $deleted++;
            }
        }

        return $deleted;
    }
}
