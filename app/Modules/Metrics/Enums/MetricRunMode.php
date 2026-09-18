<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Enums;

enum MetricRunMode: string
{
    case Full = 'full';
    case Dirty = 'dirty';
}
