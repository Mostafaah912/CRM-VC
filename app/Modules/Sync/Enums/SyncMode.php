<?php

declare(strict_types=1);

namespace App\Modules\Sync\Enums;

enum SyncMode: string
{
    case Full = 'full';
    case Incremental = 'incremental';
    case Webhook = 'webhook';
}
