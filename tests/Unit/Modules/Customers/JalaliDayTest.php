<?php

declare(strict_types=1);

use App\Modules\Customers\Support\JalaliDay;

/*
| A Jalali calendar day typed by a person (1405/03/11, 1405-03-11, Persian or ASCII digits) as the instants that bound it in
| Asia/Tehran: [start, nextStart). Tehran is UTC+3:30 all year, so 1405/01/01 begins at 2026-03-20 20:30:00 UTC.
*/

it('reads a Jalali day as the instant its Tehran day begins', function (string $input, string $utc) {
    expect(JalaliDay::start($input)?->utc()->format('Y-m-d H:i:s'))->toBe($utc);
})->with([
    'slashes' => ['1405/01/01', '2026-03-20 20:30:00'],
    'dashes' => ['1405-01-01', '2026-03-20 20:30:00'],
    'persian digits' => ['۱۴۰۵/۰۱/۰۱', '2026-03-20 20:30:00'],
    'arabic-indic digits' => ['١٤٠٥-٠١-٠١', '2026-03-20 20:30:00'],
    'surrounding blanks' => ['  1405/01/01 ', '2026-03-20 20:30:00'],
    'leap day of 1403' => ['1403/12/30', '2025-03-19 20:30:00'],
    'last day of 1405' => ['1405/12/29', '2027-03-19 20:30:00'],
]);

it('accepts the whole plausible range of Jalali years, 1300 to 1499', function () {
    expect(JalaliDay::start('1300/01/01'))->not->toBeNull()
        ->and(JalaliDay::start('1499/12/29'))->not->toBeNull();
});

it('gives the start of the NEXT day as the exclusive end, one day later', function () {
    expect(JalaliDay::nextStart('1405/01/01')?->utc()->format('Y-m-d H:i:s'))->toBe('2026-03-21 20:30:00')
        ->and(JalaliDay::nextStart('1405/12/29')?->utc()->format('Y-m-d H:i:s'))->toBe('2027-03-20 20:30:00');
});

it('rejects anything that is not a real Jalali day', function (string $input) {
    expect(JalaliDay::start($input))->toBeNull()->and(JalaliDay::nextStart($input))->toBeNull();
})->with([
    'month 13' => ['1405/13/01'],
    'month 0' => ['1405/00/10'],
    'day 32' => ['1405/02/32'],
    'day 0' => ['1405/02/00'],
    'day 31 of a 30-day month' => ['1405/07/31'],
    'esfand 30 in a common year' => ['1405/12/30'],
    'single digits' => ['1405/1/1'],
    'no separators' => ['14050101'],
    'trailing junk' => ['1405/01/01x'],
    'trailing junk after a gregorian-looking date' => ['2026-03-21x'],
    'a gregorian date is not a plausible Jalali year' => ['2026-03-21'],
    'year before 1300' => ['1299/12/29'],
    'year after 1499' => ['1500/01/01'],
    'words' => ['abc'],
    'empty' => [''],
]);
