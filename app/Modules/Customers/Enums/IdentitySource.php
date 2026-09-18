<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

enum IdentitySource: string
{
    case WooUser = 'woo_user';
    case WooGuestOrder = 'woo_guest_order';
}
