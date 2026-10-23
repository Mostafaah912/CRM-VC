<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/** P8-01: refuse to boot a production (or staging, P8-03) app that would leak stack traces and config (APP_DEBUG=true). */
final class ProductionSafety
{
    public static function assertDebugIsOff(): void
    {
        if (app()->environment('production', 'staging') && config('app.debug')) {
            throw new RuntimeException('APP_DEBUG must be false in production and staging. Set APP_DEBUG=false in .env and re-run.');
        }
    }
}
