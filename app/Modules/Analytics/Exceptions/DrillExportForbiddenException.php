<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Exceptions;

use RuntimeException;

/** `DrillService::export()` refused: the user lacks `customers.export` (same gate as `SegmentService::export()`). */
final class DrillExportForbiddenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('شما مجوز خروجی‌گیری از مشتریان را ندارید.');
    }
}
