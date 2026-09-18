<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

enum IdentityConflictStatus: string
{
    case Pending = 'pending';
    case ConfirmedSame = 'confirmed_same';
    case ConfirmedDifferent = 'confirmed_different';
    case Ignored = 'ignored';
}
