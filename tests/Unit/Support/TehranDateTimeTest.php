<?php

declare(strict_types=1);

use App\Support\TehranDateTime;
use Carbon\CarbonImmutable;

/*
| A stored instant (UTC) shown to a person: the Jalali date from JalaliDate plus the wall-clock time in Asia/Tehran.
| Tehran is UTC+3:30 all year (no DST since 2022), so the instants below are exact.
*/

it('formats an instant as a Jalali date and Tehran time', function (string $utc, string $expected) {
    expect(TehranDateTime::format(CarbonImmutable::parse($utc, 'UTC')))->toBe($expected);
})->with([
    'Nowruz 1405 starts at Tehran midnight' => ['2026-03-20 20:30:00', '1405/01/01 00:00:00'],
    'one second earlier is the last day of 1404' => ['2026-03-20 20:29:59', '1404/12/29 23:59:59'],
    'a midday instant' => ['2026-09-20 10:00:00', '1405/06/29 13:30:00'],
    'the leap day of 1403' => ['2025-03-19 20:30:00', '1403/12/30 00:00:00'],
]);

it('gives the same result whatever timezone the instant carries', function () {
    $utc = CarbonImmutable::parse('2026-03-20 20:30:00', 'UTC');

    expect(TehranDateTime::format($utc->setTimezone('America/New_York')))->toBe('1405/01/01 00:00:00')
        ->and(TehranDateTime::format($utc->setTimezone('Asia/Tehran')))->toBe('1405/01/01 00:00:00')
        ->and(TehranDateTime::format(new DateTime('2026-03-20 20:30:00', new DateTimeZone('UTC'))))->toBe('1405/01/01 00:00:00');
});

it('returns null for a missing instant', function () {
    expect(TehranDateTime::formatOrNull(null))->toBeNull()
        ->and(TehranDateTime::formatOrNull(CarbonImmutable::parse('2026-03-20 20:30:00', 'UTC')))->toBe('1405/01/01 00:00:00');
});
