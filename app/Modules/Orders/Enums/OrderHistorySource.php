<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

enum OrderHistorySource: string
{
    case Sync = 'sync';
}
