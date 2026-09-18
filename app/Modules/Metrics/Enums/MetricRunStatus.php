<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Enums;

enum MetricRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
