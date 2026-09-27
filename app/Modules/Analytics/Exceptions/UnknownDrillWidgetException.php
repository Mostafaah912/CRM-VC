<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Exceptions;

use RuntimeException;

/** `{widget}` in `GET /internal/drill/{widget}` is not one of `DrillService`'s known widgets. */
final class UnknownDrillWidgetException extends RuntimeException
{
    public function __construct(string $widget)
    {
        parent::__construct("ویجت «{$widget}» شناخته‌شده نیست.");
    }
}
