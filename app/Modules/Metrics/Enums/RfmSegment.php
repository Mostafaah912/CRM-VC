<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Enums;

enum RfmSegment: string
{
    case Champion = 'champion';
    case Loyal = 'loyal';
    case Promising = 'promising';
    case NewCustomer = 'new_customer';
    case AtRisk = 'at_risk';
    case CantLose = 'cant_lose';
    case Hibernating = 'hibernating';
    case Lost = 'lost';
}
