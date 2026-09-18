<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Enums;

enum ClvConfidence: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
