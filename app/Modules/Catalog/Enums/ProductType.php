<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

enum ProductType: string
{
    case Simple = 'simple';
    case Variable = 'variable';
    case Grouped = 'grouped';
    case External = 'external';
}
