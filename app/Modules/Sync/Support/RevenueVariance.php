<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

use InvalidArgumentException;
use OverflowException;

/**
 * The revenue difference between Woo and the local orders, in integer Toman only (CLAUDE.md §2: no floats for money).
 *
 *  - diff = woo - local (signed);
 *  - the percentage is ABSOLUTE, in percent units scaled to 4 decimals (500 = 0.0500%), rounded half up by long division,
 *    and capped at 999.9999 — the limit of numeric(7,4). With no Woo revenue the ratio is undefined: equal zeros are 0,
 *    anything else is reported as the cap;
 *  - "within tolerance" is the exact integer test abs(diff) * 100 < woo — strictly under 1% (CLAUDE.md §11) — never derived
 *    from the rounded percentage, so 0.99996% is not rounded into a red 1.0000%.
 *
 * Sums beyond what integer arithmetic can hold without overflow are refused rather than approximated.
 */
final readonly class RevenueVariance
{
    /** 999.9999 percent, scaled. */
    public const CAP_SCALED = 9_999_999;

    /** Largest revenue sum accepted: every intermediate product below stays under PHP_INT_MAX. */
    private const MAX_SUM = 900_000_000_000_000_000;

    private const MAX_DIFF = 92_233_720_368_547_758;

    private function __construct(
        public int $diff,
        public int $percentScaled,
        private bool $withinTolerance,
    ) {}

    public static function between(int $wooRevenue, int $localRevenue): self
    {
        if ($wooRevenue < 0 || $localRevenue < 0) {
            throw new InvalidArgumentException('Revenue cannot be negative.');
        }

        if ($wooRevenue > self::MAX_SUM || $localRevenue > self::MAX_SUM || abs($wooRevenue - $localRevenue) > self::MAX_DIFF) {
            throw new OverflowException('Revenue is too large for exact integer arithmetic.');
        }

        $diff = $wooRevenue - $localRevenue;
        $magnitude = abs($diff);

        if ($wooRevenue === 0) {
            return new self($diff, $magnitude === 0 ? 0 : self::CAP_SCALED, $magnitude === 0);
        }

        $hundredths = $magnitude * 100;
        $whole = intdiv($hundredths, $wooRevenue);
        $within = $hundredths < $wooRevenue;

        if ($whole >= 1000) {
            return new self($diff, self::CAP_SCALED, $within);
        }

        $remainder = $hundredths % $wooRevenue;
        $fraction = 0;

        for ($digit = 0; $digit < 4; $digit++) {
            $remainder *= 10;
            $fraction = $fraction * 10 + intdiv($remainder, $wooRevenue);
            $remainder %= $wooRevenue;
        }

        if ($remainder * 2 >= $wooRevenue) {
            $fraction++;
        }

        return new self($diff, min(self::CAP_SCALED, $whole * 10_000 + $fraction), $within);
    }

    /** "0.0500": the absolute percentage with four decimals. */
    public function percent(): string
    {
        return sprintf('%d.%04d', intdiv($this->percentScaled, 10_000), $this->percentScaled % 10_000);
    }

    public function isWithinTolerance(): bool
    {
        return $this->withinTolerance;
    }
}
