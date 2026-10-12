<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools\Tools;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/** "Last N days ending at the newest daily_metrics date" — data may lag today, so the window anchors on the data, not the clock. */
final class Period
{
    public static function end(ConnectionInterface $db): ?CarbonImmutable
    {
        $max = $db->table('daily_metrics')->max('date');

        return $max === null ? null : CarbonImmutable::parse((string) $max, 'Asia/Tehran')->startOfDay();
    }
}
