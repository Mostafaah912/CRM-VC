<?php

declare(strict_types=1);

namespace App\Modules\Ai\Anomaly;

use InvalidArgumentException;

/** Median / MAD — the outlier-resistant location and scale the detector is built on. Statistics, not money: floats are fine here. */
final class RobustStats
{
    /** @param array<array-key, int|float> $values */
    public static function median(array $values): float
    {
        $values = array_values($values);

        if ($values === []) {
            throw new InvalidArgumentException('median of an empty set');
        }

        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * Median absolute deviation (unscaled).
     *
     * @param  array<array-key, int|float>  $values
     */
    public static function mad(array $values, float $median): float
    {
        return self::median(array_map(fn ($v) => abs($v - $median), $values));
    }
}
