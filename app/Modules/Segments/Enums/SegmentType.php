<?php

declare(strict_types=1);

namespace App\Modules\Segments\Enums;

enum SegmentType: string
{
    case Dynamic = 'dynamic';
    case Static = 'static';
    case Manual = 'manual';
}
