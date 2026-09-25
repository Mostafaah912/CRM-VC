<?php

declare(strict_types=1);

namespace App\Modules\Sync\Enums;

/** The outcome of reconciling one Jalali month. `failed` means the month could not be read at all — it is never a result. */
enum ReconciliationStatus: string
{
    case Green = 'green';
    case Red = 'red';
    case Failed = 'failed';
}
