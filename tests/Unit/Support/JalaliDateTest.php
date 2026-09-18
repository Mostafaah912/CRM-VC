<?php

declare(strict_types=1);

use App\Support\JalaliDate;
use Carbon\CarbonImmutable;

it('converts known Nowruz anchor dates from Gregorian to Jalali', function (string $gregorian, array $expected) {
    $date = CarbonImmutable::parse($gregorian, 'Asia/Tehran');

    expect(JalaliDate::toJalaliParts($date))->toBe($expected);
})->with([
    ['2020-03-20', [1399, 1, 1]],
    ['2021-03-21', [1400, 1, 1]],
    ['2022-03-21', [1401, 1, 1]],
    ['2023-03-21', [1402, 1, 1]],
    ['2024-03-20', [1403, 1, 1]],
]);

it('converts the leap-year intercalary day of 1399 correctly', function () {
    // 1399 is a Jalali leap year: Esfand has 30 days, and 1399/12/30 is the
    // day immediately before Nowruz 1400 (2021-03-21).
    $date = CarbonImmutable::parse('2021-03-20', 'Asia/Tehran');

    expect(JalaliDate::toJalaliParts($date))->toBe([1399, 12, 30]);
});

it('round-trips Jalali to Gregorian back to the same Jalali date', function () {
    foreach ([[1399, 1, 1], [1400, 6, 31], [1402, 11, 11], [1403, 12, 29], [1399, 12, 30]] as [$jy, $jm, $jd]) {
        $gregorian = JalaliDate::toGregorian($jy, $jm, $jd);

        expect(JalaliDate::toJalaliParts($gregorian))->toBe([$jy, $jm, $jd]);
    }
});

it('round-trips a wide range of Gregorian dates through Jalali and back', function () {
    $start = CarbonImmutable::parse('2015-01-01', 'Asia/Tehran');

    for ($i = 0; $i < 3000; $i += 37) {
        $date = $start->addDays($i);
        [$jy, $jm, $jd] = JalaliDate::toJalaliParts($date);
        $back = JalaliDate::toGregorian($jy, $jm, $jd);

        expect($back->isSameDay($date))->toBeTrue();
    }
});

it('formats a date as a Jalali Y/m/d string', function () {
    $date = CarbonImmutable::parse('2024-03-20', 'Asia/Tehran');

    expect(JalaliDate::format($date))->toBe('1403/01/01');
});

it('formats the Jalali month key used for cohorts', function () {
    $date = CarbonImmutable::parse('2024-03-20', 'Asia/Tehran');

    expect(JalaliDate::toJalaliMonth($date))->toBe('1403-01');
});

it('returns the correct Persian month name', function () {
    expect(JalaliDate::monthName(1))->toBe('فروردین');
    expect(JalaliDate::monthName(7))->toBe('مهر');
    expect(JalaliDate::monthName(12))->toBe('اسفند');
});

it('converts a UTC timestamp to Tehran-local Jalali display', function () {
    // 2024-03-19 21:00 UTC == 2024-03-20 00:30 Asia/Tehran (+03:30) == Jalali 1403-01-01.
    $utc = CarbonImmutable::parse('2024-03-19 21:00:00', 'UTC');

    expect(JalaliDate::format($utc))->toBe('1403/01/01');
});
