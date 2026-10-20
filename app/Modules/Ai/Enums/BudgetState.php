<?php

declare(strict_types=1);

namespace App\Modules\Ai\Enums;

enum BudgetState: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Blocked = 'blocked';
}
