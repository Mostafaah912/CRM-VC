<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Enums;

enum AffinityLevel: string
{
    case Variation = 'variation';
    case Product = 'product';
    case Category = 'category';
    case Basket = 'basket';
}
