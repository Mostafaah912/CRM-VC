<?php

declare(strict_types=1);

namespace App\Modules\Sync\Enums;

enum SyncStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Partial = 'partial';
}
