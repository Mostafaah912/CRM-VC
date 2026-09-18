<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

enum CustomerStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
    case Anonymized = 'anonymized';
}
