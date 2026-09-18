<?php

declare(strict_types=1);

use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * P0-04: to_jalali / to_jalali_month / jalali_month_diff must run natively
 * inside PostgreSQL (Metrics/Cohort SQL depends on them, never PHP loops).
 * These are the same anchor dates verified against App\Support\JalaliDate
 * in P0-03, so both sides of the stack agree.
 */
function selectJalali(string $timestamptz): ?string
{
    return DB::selectOne('select to_jalali(?::timestamptz) as value', [$timestamptz])->value;
}

function selectJalaliMonth(string $timestamptz): ?string
{
    return DB::selectOne('select to_jalali_month(?::timestamptz) as value', [$timestamptz])->value;
}

function selectMonthDiff(string $a, string $b): int
{
    return (int) DB::selectOne('select jalali_month_diff(?, ?) as value', [$a, $b])->value;
}

it('converts a plain Gregorian date to Jalali', function () {
    expect(selectJalali('2024-06-15 00:00:00+03:30'))->toBe('1403-03-26');
});

it('converts known Nowruz boundary dates', function (string $gregorian, string $expectedJalali) {
    expect(selectJalali($gregorian))->toBe($expectedJalali);
})->with([
    ['2020-03-20 00:00:00+03:30', '1399-01-01'],
    ['2021-03-21 00:00:00+03:30', '1400-01-01'],
    ['2022-03-21 00:00:00+03:30', '1401-01-01'],
    ['2023-03-21 00:00:00+03:30', '1402-01-01'],
    ['2024-03-20 00:00:00+03:30', '1403-01-01'],
]);

it('handles the day just before Nowruz (end of previous Jalali year)', function () {
    // 1402 is a common (365-day) Jalali year: 2023-03-21 -> 2024-03-20 is exactly
    // 365 days, so Esfand 1402 has 29 days, not 30.
    expect(selectJalali('2024-03-19 00:00:00+03:30'))->toBe('1402-12-29');
});

it('converts the leap-year intercalary day of 1399 (30 Esfand)', function () {
    expect(selectJalali('2021-03-20 00:00:00+03:30'))->toBe('1399-12-30');
});

it('converts a UTC timestamp via Asia/Tehran, not the raw UTC date', function () {
    // 2024-03-19 21:00 UTC == 2024-03-20 00:30 Asia/Tehran (+03:30) == Jalali 1403-01-01.
    expect(selectJalali('2024-03-19 21:00:00+00'))->toBe('1403-01-01');
});

it('returns null for a null timestamp', function () {
    expect(DB::selectOne('select to_jalali(null::timestamptz) as value')->value)->toBeNull();
});

it('derives the 7-character cohort month key', function () {
    expect(selectJalaliMonth('2024-03-20 00:00:00+03:30'))->toBe('1403-01');
    expect(selectJalaliMonth('2024-06-15 00:00:00+03:30'))->toBe('1403-03');
});

it('returns zero month diff for the same month', function () {
    expect(selectMonthDiff('1403-01', '1403-01'))->toBe(0);
});

it('returns one month diff for the next month', function () {
    expect(selectMonthDiff('1403-01', '1403-02'))->toBe(1);
});

it('crosses a Jalali year boundary correctly', function () {
    expect(selectMonthDiff('1402-11', '1403-02'))->toBe(3);
});

it('computes a full year diff', function () {
    expect(selectMonthDiff('1402-01', '1403-01'))->toBe(12);
});

it('returns a negative diff when the second month precedes the first', function () {
    expect(selectMonthDiff('1403-05', '1403-02'))->toBe(-3);
});

it('is consistent with App\Support\JalaliDate for a spread of real order-like timestamps', function () {
    $samples = [
        '2024-09-25 10:15:00+03:30',
        '2025-01-05 23:59:59+03:30',
        '2025-03-20 12:00:00+03:30',
        '2026-02-18 08:30:00+03:30',
    ];

    foreach ($samples as $sample) {
        $carbon = CarbonImmutable::parse($sample);
        $expected = JalaliDate::format($carbon, '-');

        expect(selectJalali($sample))->toBe($expected);
    }
});
