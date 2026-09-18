<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

enum PermissionEffect: string
{
    case Allow = 'allow';
    case Deny = 'deny';
}
