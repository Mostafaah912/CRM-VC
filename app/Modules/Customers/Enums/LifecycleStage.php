<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

enum LifecycleStage: string
{
    case Prospect = 'prospect';
    case New = 'new';
    case Active = 'active';
    case Repeat = 'repeat';
    case Loyal = 'loyal';
    case AtRisk = 'at_risk';
    case Dormant = 'dormant';
    case Lost = 'lost';
}
