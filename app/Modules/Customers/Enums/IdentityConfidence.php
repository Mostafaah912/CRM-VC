<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

enum IdentityConfidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
