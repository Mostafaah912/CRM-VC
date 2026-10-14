<?php

declare(strict_types=1);

use App\Modules\Ai\Anomaly\AnomalyConfig;
use App\Modules\Ai\Anomaly\AnomalyEngine;
use Carbon\CarbonImmutable;
use Tests\Support\AnomalySeries;

/*
| P7-05 — the pure statistics. No database, no container beyond config(): the engine takes rows and returns a report.
| The synthetic series (tests/Support/AnomalySeries.php) has a fixed Iranian weekly shape and tiny deterministic noise.
| 2026-10-14 is a Wednesday; the as-of day is 2026-10-13 (a Tuesday) unless a test says otherwise.
*/

function cfg(array $override = []): AnomalyConfig
{
    config(['ai.anomaly' => array_replace_recursive(config('ai.anomaly'), $override)]);

    return AnomalyConfig::fromConfig();
}

function analyze(array $rows, int $days = 14, string $asOf = '2026-10-13', ?AnomalyConfig $config = null)
{
    return (new AnomalyEngine)->analyze($rows, CarbonImmutable::parse($asOf), $days, $config ?? cfg(), 0);
}

function summary(array $events): array
{
    return array_map(fn ($e) => [$e->metric, $e->from, $e->to, $e->direction, $e->severity], $events);
}

it('finds exactly the injected spike and drop, with the right direction and severity — and nothing else', function () {
    $rows = AnomalySeries::make('2026-06-01', '2026-10-13', [
        '2026-10-07' => ['orders_count' => 130, 'net_revenue' => 130 * 200_000, 'customers_new' => 52, 'customers_repeat' => 78, 'customers_total' => 130, 'revenue' => 26_800_000, 'refunds' => 800_000],   // Wed spike
        '2026-10-10' => ['orders_count' => 12, 'net_revenue' => 12 * 200_000, 'customers_new' => 5, 'customers_repeat' => 7, 'customers_total' => 12, 'revenue' => 2_480_000, 'refunds' => 80_000],       // Sat drop
    ]);

    $report = analyze($rows);
    $found = summary($report->events);

    expect($report->status)->toBe('ok')
        ->and($found)->toContain(['orders_count', '2026-10-07', '2026-10-07', 'up', 'critical'])
        ->and($found)->toContain(['net_revenue', '2026-10-07', '2026-10-07', 'up', 'critical'])
        ->and($found)->toContain(['orders_count', '2026-10-10', '2026-10-10', 'down', 'warning'])   // 12 vs 40: z ~ -7 with the 10% sigma floor
        ->and($found)->toContain(['net_revenue', '2026-10-10', '2026-10-10', 'down', 'warning'])
        ->and(array_values(array_unique(array_map(fn ($e) => $e->peakDate, $report->events))))->toEqualCanonicalizing(['2026-10-07', '2026-10-10']);

    $spike = collect($report->events)->first(fn ($e) => $e->metric === 'orders_count' && $e->peakDate === '2026-10-07');
    expect($spike->observed)->toBe(130)->and($spike->expected)->toBe(43)->and($spike->deviationPercent)->toBe(202.33)->and($spike->robustZ)->toBeGreaterThan(8.0)->and($spike->unit)->toBe('count')->and($spike->knownEvent)->toBeNull();
});

it('does not treat the normal Thursday/Friday weekend dip as an anomaly (same-weekday baseline)', function () {
    $report = analyze(AnomalySeries::make('2026-06-01', '2026-10-13'), days: 56);

    expect($report->status)->toBe('ok')->and($report->events)->toBe([])->and($report->evaluated)->toBeGreaterThan(200);
});

it('is NOT fooled by an outlier already inside the baseline (MAD resistance)', function () {
    // 2026-09-30 (Wed) is a giant one-off; the NEXT Wednesday (10-07) is perfectly normal, the one after (10-14 is future) — so test 10-07 normal...
    $rows = AnomalySeries::make('2026-06-01', '2026-10-13', ['2026-09-30' => ['orders_count' => 400, 'net_revenue' => 80_000_000, 'revenue' => 82_000_000, 'refunds' => 2_000_000, 'customers_new' => 160, 'customers_repeat' => 240, 'customers_total' => 400]]);
    $normalAfter = analyze($rows, days: 6);   // 10-08 .. 10-13: includes no Wednesday after the outlier except none; use 14 days to include 10-07
    $wide = analyze($rows, days: 14);

    expect(array_filter($wide->events, fn ($e) => $e->peakDate === '2026-10-07'))->toBe([])        // normal Wed next to a polluted baseline stays normal
        ->and(summary($wide->events))->toContain(['orders_count', '2026-09-30', '2026-09-30', 'up', 'critical'])
        ->and($normalAfter->events)->toBe([]);

    // ...and the polluted baseline does not blunt a REAL spike either.
    $rows2 = AnomalySeries::make('2026-06-01', '2026-10-13', ['2026-09-30' => ['orders_count' => 400, 'net_revenue' => 80_000_000], '2026-10-07' => ['orders_count' => 130, 'net_revenue' => 26_000_000]]);
    expect(summary(analyze($rows2, days: 14)->events))->toContain(['orders_count', '2026-10-07', '2026-10-07', 'up', 'critical']);
});

it('answers "insufficient data" — not an anomaly — when there is too little same-weekday history', function () {
    $short = AnomalySeries::make('2026-09-25', '2026-10-13', ['2026-10-12' => ['orders_count' => 500, 'net_revenue' => 100_000_000]]);   // 19 days: <4 same-weekday samples
    $report = analyze($short);

    expect($report->status)->toBe('insufficient_data')->and($report->events)->toBe([])->and($report->evaluated)->toBe(0)
        ->and(analyze([])->status)->toBe('no_data');
});

it('makes no anomaly from a tiny store, even for a huge relative jump (volume guard)', function () {
    $tiny = array_map(fn ($r) => array_merge($r, ['orders_count' => 1, 'net_revenue' => 200_000, 'customers_new' => 1, 'customers_total' => 1, 'customers_repeat' => 0, 'revenue' => 206_000, 'refunds' => 6_000]), AnomalySeries::make('2026-06-01', '2026-10-13'));
    $tiny[count($tiny) - 1]['orders_count'] = 9;
    $tiny[count($tiny) - 1]['net_revenue'] = 1_800_000;

    $report = analyze($tiny);

    expect($report->status)->toBe('low_volume')->and($report->events)->toBe([])->and($report->lowVolumeSkips)->toBeGreaterThan(0);
});

it('does not invent a z when the MAD is zero: a perfectly flat baseline needs a real jump', function () {
    $flat = array_map(fn ($r) => array_merge($r, ['orders_count' => 20, 'net_revenue' => 4_000_000, 'revenue' => 4_100_000, 'refunds' => 100_000, 'customers_new' => 8, 'customers_total' => 20, 'customers_repeat' => 12]), AnomalySeries::make('2026-06-01', '2026-10-13'));
    $idx = count($flat) - 1;

    $flat[$idx]['orders_count'] = 22;   // +10%: relative guard (and z floor) say no
    expect(analyze($flat, days: 1)->events)->toBe([]);

    $flat[$idx]['orders_count'] = 25;   // +25%: z = 5 / (0.1*20) = 2.5 < 3.5 -> still nothing
    expect(analyze($flat, days: 1)->events)->toBe([]);

    $flat[$idx]['orders_count'] = 28;   // +40%: passes every guard, but the z is capped by the 10% sigma floor -> info, NOT a MAD-0 "critical"
    expect(summary(analyze($flat, days: 1)->events))->toContain(['orders_count', '2026-10-13', '2026-10-13', 'up', 'info']);

    $flat[$idx]['orders_count'] = 40;   // +100%: z = 20/2 = 10 -> a real, critical anomaly
    $events = analyze($flat, days: 1)->events;

    expect(summary($events))->toContain(['orders_count', '2026-10-13', '2026-10-13', 'up', 'critical']);
});

it('requires a minimum absolute change too: a tiny jump on a small number is not an anomaly', function () {
    $rows = array_map(fn ($r) => array_merge($r, ['orders_count' => 10, 'net_revenue' => 2_000_000, 'revenue' => 2_060_000, 'refunds' => 60_000, 'customers_new' => 2, 'customers_total' => 10, 'customers_repeat' => 8]), AnomalySeries::make('2026-06-01', '2026-10-13'));
    $rows[count($rows) - 1]['customers_new'] = 4;   // +100%, but only +2... min_abs is 2 so this IS allowed; make it +1 instead:
    $rows[count($rows) - 1]['customers_new'] = 3;   // +50%, +1 < min_abs 2

    expect(array_filter(analyze($rows, days: 1)->events, fn ($e) => $e->metric === 'customers_new'))->toBe([]);
});

it('folds consecutive anomalous days of the same metric and direction into one dated event', function () {
    $over = [];

    foreach (['2026-10-09' => 3, '2026-10-10' => 4, '2026-10-11' => 5] as $date => $_) {
        $over[$date] = ['orders_count' => 5, 'net_revenue' => 5 * 200_000, 'revenue' => 1_030_000, 'refunds' => 30_000, 'customers_new' => 2, 'customers_total' => 5, 'customers_repeat' => 3];
    }

    $events = analyze(AnomalySeries::make('2026-06-01', '2026-10-13', $over))->events;
    $orders = array_values(array_filter($events, fn ($e) => $e->metric === 'orders_count'));

    expect($orders)->toHaveCount(1)->and($orders[0]->from)->toBe('2026-10-09')->and($orders[0]->to)->toBe('2026-10-11')->and($orders[0]->days)->toBe(3)
        ->and($orders[0]->direction)->toBe('down')->and($orders[0]->severity)->toBe('critical');
});

it('keeps a non-consecutive pair of anomalies as two events', function () {
    $over = ['2026-10-07' => ['orders_count' => 130, 'net_revenue' => 26_000_000], '2026-10-11' => ['orders_count' => 120, 'net_revenue' => 24_000_000]];
    $orders = array_values(array_filter(analyze(AnomalySeries::make('2026-06-01', '2026-10-13', $over))->events, fn ($e) => $e->metric === 'orders_count'));

    expect($orders)->toHaveCount(2);
});

it('labels an anomaly inside a configured known event but never drops it', function () {
    $rows = AnomalySeries::make('2026-06-01', '2026-10-13', ['2026-10-07' => ['orders_count' => 130, 'net_revenue' => 26_000_000]]);

    $plain = analyze($rows);
    $labelled = analyze($rows, config: cfg(['known_events' => [['name' => 'mid_season_sale', 'from' => '2026-10-05', 'to' => '2026-10-09'], ['name' => 'other', 'from' => '2026-11-01', 'to' => '2026-11-02']]]));

    expect(count($labelled->events))->toBe(count($plain->events))
        ->and(collect($labelled->events)->first(fn ($e) => $e->peakDate === '2026-10-07')->knownEvent)->toBe('mid_season_sale')
        ->and(collect($plain->events)->first(fn ($e) => $e->peakDate === '2026-10-07')->knownEvent)->toBeNull()
        ->and((require base_path('config/ai.php'))['anomaly']['known_events'])->toBe([]);
});

it('ranks events by severity, then newest, and is fully deterministic', function () {
    $rows = AnomalySeries::make('2026-06-01', '2026-10-13', [
        '2026-10-07' => ['orders_count' => 130, 'net_revenue' => 26_000_000],
        '2026-10-10' => ['orders_count' => 12, 'net_revenue' => 2_400_000],
        '2026-10-12' => ['orders_count' => 62, 'net_revenue' => 12_400_000],   // Monday: moderate spike
    ]);

    $a = analyze($rows);
    $b = analyze($rows);
    $order = ['critical' => 3, 'warning' => 2, 'info' => 1];
    $keys = array_map(fn ($e) => [$order[$e->severity], $e->to], $a->events);
    $sorted = $keys;
    usort($sorted, fn ($x, $y) => [$y[0], $y[1]] <=> [$x[0], $x[1]]);

    expect($a)->toEqual($b)->and(json_encode($a))->toBe(json_encode($b))->and($keys)->toBe($sorted);
});

it('reports money as integer Toman and rates as two-decimal percents', function () {
    $rows = AnomalySeries::make('2026-06-01', '2026-10-13', ['2026-10-13' => ['revenue' => 10_000_000, 'refunds' => 4_000_000, 'net_revenue' => 6_000_000]]);   // 40% refund rate vs ~3%
    $rate = collect(analyze($rows, days: 1)->events)->first(fn ($e) => $e->metric === 'refund_rate');

    expect($rate)->not->toBeNull()->and($rate->unit)->toBe('percent')->and($rate->direction)->toBe('up')->and($rate->observed)->toBe(40.0)->and($rate->expected)->toBeFloat()
        ->and(round($rate->expected, 2))->toBe($rate->expected);

    $big = AnomalySeries::make('2026-06-01', '2026-10-13', ['2026-10-13' => ['orders_count' => 20, 'net_revenue' => 4_000_000]]);
    $money = collect(analyze($big, days: 1)->events)->first(fn ($e) => $e->metric === 'net_revenue');
    expect($money)->not->toBeNull()->and($money->observed)->toBeInt()->and($money->expected)->toBeInt()->and($money->unit)->toBe('toman')->and($money->direction)->toBe('down');
});

it('stays fast over three years of history', function () {
    $rows = AnomalySeries::make('2023-10-01', '2026-10-13');
    $t = microtime(true);
    $report = analyze($rows, days: 1100);

    expect(count($rows))->toBeGreaterThan(1090)->and(microtime(true) - $t)->toBeLessThan(2.0)->and($report->status)->toBe('ok')->and($report->events)->toBe([]);
});
