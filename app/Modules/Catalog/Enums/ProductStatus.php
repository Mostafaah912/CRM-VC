<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

enum ProductStatus: string
{
    case Publish = 'publish';
    case Draft = 'draft';
    case Pending = 'pending';
    case Private = 'private';
}
