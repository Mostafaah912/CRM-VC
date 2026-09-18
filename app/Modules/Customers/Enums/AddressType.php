<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

enum AddressType: string
{
    case Billing = 'billing';
    case Shipping = 'shipping';
}
