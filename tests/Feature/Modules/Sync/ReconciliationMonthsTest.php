<?php

declare(strict_types=1);

use App\Modules\Sync\Support\ReconciliationMonths;
use Carbon\CarbonImmutable;

/*
| P2-11 Jalali months. A month is "YYYY-MM" (Jalali, ASCII digits); its window is half-open in UTC:
| [first day 00:00 Asia/Tehran, first day of the next month 00:00 Asia/Tehran), converted with JalaliDate. The first month
| is the month of woo.sync_epoch (Mehr 1403); the "last complete month" is the month before the current Jalali month.
*/

function months(): ReconciliationMonths
{
    return new ReconciliationMonths;
}

function at(string $utc): void
{
    test()->travelTo(CarbonImmutable::parse($utc, 'UTC'));
}

function iso(CarbonImmutable $moment): string
{
    return $moment->utc()->format('Y-m-d\TH:i:s');
}

it('turns a Jalali month into its half-open UTC window', function (string $month, string $start, string $end) {
    [$from, $until] = months()->window($month);

    expect([iso($from), iso($until)])->toBe([$start, $end]);
})->with([
    'Mehr 1403 (30 days)' => ['1403-07', '2024-09-21T20:30:00', '2024-10-21T20:30:00'],
    'Aban 1403' => ['1403-08', '2024-10-21T20:30:00', '2024-11-20T20:30:00'],
    'Esfand 1403 (30 days, a leap year) ends at Nowruz 1404' => ['1403-12', '2025-02-18T20:30:00', '2025-03-20T20:30:00'],
    'Farvardin 1404 (31 days)' => ['1404-01', '2025-03-20T20:30:00', '2025-04-20T20:30:00'],
]);

it('gives adjacent months windows that touch exactly, with nothing lost or counted twice', function () {
    $months = ['1403-07', '1403-08', '1403-09', '1403-10', '1403-11', '1403-12', '1404-01'];

    foreach (array_map(null, array_slice($months, 0, -1), array_slice($months, 1)) as [$a, $b]) {
        expect(iso(months()->window($a)[1]))->toBe(iso(months()->window($b)[0]));
    }
});

it('gives the Tehran dates a report shows: first and last day of the month', function () {
    expect(months()->dates('1403-07'))->toBe(['2024-09-22', '2024-10-21'])
        ->and(months()->dates('1403-12'))->toBe(['2025-02-19', '2025-03-20']);
});

it('rejects anything that is not a Jalali YYYY-MM', function (string $month) {
    months()->window($month);
})->with([
    'one-digit month' => ['1403-7'],
    'slash' => ['1403/07'],
    'month 13' => ['1403-13'],
    'month 00' => ['1403-00'],
    'five-digit year' => ['14030-07'],
    'leading space' => [' 1403-07'],
    'trailing space' => ['1403-07 '],
    'trailing newline' => ["1403-07\n"],
    'Persian digits' => ['۱۴۰۳-۰۷'],
    'a date' => ['1403-07-01'],
    'empty' => [''],
    'text' => ['mehr'],
])->throws(InvalidArgumentException::class);

it('finds the last complete month: the month before the current one in Tehran', function (string $now, string $expected) {
    at($now);

    expect(months()->lastComplete())->toBe($expected);
})->with([
    'mid month' => ['2025-01-10 12:00:00', '1403-09'],                       // Tehran: 1403-10-21
    'the very last second of a month' => ['2024-12-20 20:29:59', '1403-08'],   // Tehran: 1403-09-30 23:59:59
    'the first second of the next' => ['2024-12-20 20:30:00', '1403-09'],      // Tehran: 1403-10-01 00:00:00
    'across the year' => ['2025-03-25 10:00:00', '1403-12'],                   // Tehran: 1404-01-05
]);

it('lists the months from the epoch month to the last complete one', function () {
    at('2025-01-10 12:00:00');

    expect(months()->all())->toBe(['1403-07', '1403-08', '1403-09']);

    at('2025-05-01 12:00:00'); // Tehran: 1404-02-12 -> last complete 1404-01

    expect(months()->all())->toBe(['1403-07', '1403-08', '1403-09', '1403-10', '1403-11', '1403-12', '1404-01']);
});

it('lists nothing while the first month is still running', function () {
    at('2024-10-01 12:00:00'); // Tehran: 1403-07-10

    expect(months()->all())->toBe([])->and(months()->lastComplete())->toBe('1403-06');
});

it('starts at the month of the configured epoch, not at a hardcoded one', function () {
    config(['woo.sync_epoch' => '2025-04-01T00:00:00+00:00']); // Tehran: 1404-01-12
    at('2025-08-01 12:00:00');                                  // Tehran: 1404-05-10

    expect(months()->first())->toBe('1404-01')->and(months()->all())->toBe(['1404-01', '1404-02', '1404-03', '1404-04']);
});

it('accepts only months from the first one up to the last complete one', function (string $now, string $month, bool $ok) {
    at($now);

    if ($ok) {
        months()->assertReconcilable($month);
        expect(true)->toBeTrue();

        return;
    }

    expect(fn () => months()->assertReconcilable($month))->toThrow(InvalidArgumentException::class);
})->with([
    'the first month, once complete' => ['2024-10-25 12:00:00', '1403-07', true], // Tehran 1403-08-04
    'a complete month' => ['2025-01-10 12:00:00', '1403-08', true],
    'the last complete month' => ['2025-01-10 12:00:00', '1403-09', true],
    'the current month' => ['2025-01-10 12:00:00', '1403-10', false],
    'a future month' => ['2025-01-10 12:00:00', '1404-01', false],
    'a month before the epoch month' => ['2025-01-10 12:00:00', '1403-06', false],
]);

it('has static messages: nothing typed by the user is echoed back', function () {
    at('2025-01-10 12:00:00');

    foreach (['1403-99', '1403-06', '1404-01'] as $bad) {
        try {
            months()->assertReconcilable($bad);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())->not->toContain($bad);
        }
    }
});
