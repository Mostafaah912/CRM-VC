<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

enum AuditActorType: string
{
    case User = 'user';
    case System = 'system';
    case Ai = 'ai';
}
