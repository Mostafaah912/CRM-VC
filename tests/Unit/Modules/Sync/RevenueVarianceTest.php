<?php

declare(strict_types=1);

use App\Modules\Sync\Support\RevenueVariance;

/*
| P2-11 revenue variance — integer Toman only (CLAUDE.md §2: no floats for money). diff = woo - local (signed); the
| percentage is absolute, scaled to 4 decimals (numeric(7,4), percent units: 0.05 = 0.05%) and capped at the column's
| 999.9999; "within tolerance" is the exact integer test abs(diff) * 100 < woo_revenue — strictly under 1%.
*/

it('reports no variance for equal revenue', function () {
    $v = RevenueVariance::between(1_500_000, 1_500_000);

    expect($v->diff)->toBe(0)->and($v->percent())->toBe('0.0000')->and($v->isWithinTolerance())->toBeTrue();
});

it('measures the variance in percent with four decimals, from the sample row of the schema test', function () {
    $v = RevenueVariance::between(2_000_000_000, 1_999_000_000);

    expect($v->diff)->toBe(1_000_000)->and($v->percent())->toBe('0.0500')->and($v->percentScaled)->toBe(500);
});

it('draws the line strictly at 1%: exactly 1% is out, anything under is in', function (int $woo, int $local, string $percent, bool $within) {
    $v = RevenueVariance::between($woo, $local);

    expect($v->percent())->toBe($percent)->and($v->isWithinTolerance())->toBe($within);
})->with([
    'exactly 1%' => [100, 99, '1.0000', false],
    'exactly 1% of a large sum' => [1_000_000, 990_000, '1.0000', false],
    'one Toman under 1%' => [1_000_000, 990_001, '0.9999', true],
    '0.99%' => [10_000, 9_901, '0.9900', true],
    '0.995%' => [200_000, 198_010, '0.9950', true],
    'just over 1%' => [1_000_000, 989_999, '1.0001', false],
]);

it('uses the absolute percentage but keeps the sign of the difference: local above Woo is a variance too', function () {
    $v = RevenueVariance::between(1_000, 1_005);

    expect($v->diff)->toBe(-5)->and($v->percent())->toBe('0.5000')->and($v->isWithinTolerance())->toBeTrue()
        ->and(RevenueVariance::between(1_000, 1_020)->isWithinTolerance())->toBeFalse();
});

it('rounds half up at the fourth decimal', function (int $woo, int $local, string $percent) {
    expect(RevenueVariance::between($woo, $local)->percent())->toBe($percent);
})->with([
    'a third' => [3, 2, '33.3333'],
    'two thirds' => [3, 1, '66.6667'],
    'a sixteenth of a percent' => [80_000, 79_999, '0.0013'],
]);

it('has an undefined ratio when Woo revenue is zero: zero on both sides is fine, anything local is capped at the column limit', function () {
    $both = RevenueVariance::between(0, 0);
    $local = RevenueVariance::between(0, 250_000);

    expect($both->percent())->toBe('0.0000')->and($both->isWithinTolerance())->toBeTrue()
        ->and($local->diff)->toBe(-250_000)->and($local->percent())->toBe('999.9999')->and($local->isWithinTolerance())->toBeFalse();
});

it('caps the percentage at 999.9999 so it always fits numeric(7,4)', function () {
    $v = RevenueVariance::between(1, 5_000);

    expect($v->percent())->toBe('999.9999')->and($v->percentScaled)->toBe(9_999_999)->and($v->isWithinTolerance())->toBeFalse();
});

it('does the arithmetic in integers, exactly, on very large sums', function () {
    $v = RevenueVariance::between(1_000_000_000_000_000, 999_000_000_000_000);
    $tiny = RevenueVariance::between(10_000_000_000_000, 9_999_999_999_999);

    expect($v->percent())->toBe('0.1000')->and($v->isWithinTolerance())->toBeTrue()
        ->and($tiny->diff)->toBe(1)->and($tiny->percent())->toBe('0.0000')->and($tiny->isWithinTolerance())->toBeTrue();
});

it('refuses sums so large that the integer arithmetic could overflow, rather than fall back to floats', function () {
    RevenueVariance::between(PHP_INT_MAX, 1);
})->throws(OverflowException::class);
