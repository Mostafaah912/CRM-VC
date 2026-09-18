<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Enums;

enum ChurnRiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Lost = 'lost';
}
