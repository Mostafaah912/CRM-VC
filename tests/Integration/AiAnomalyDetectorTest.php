<?php

declare(strict_types=1);

use App\Modules\Ai\Anomaly\AnomalyDetector;
use App\Modules\Ai\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Tests\Support\AiToolsFixture;
use Tests\Support\AnomalySeries;

/*
| P7-05 — AnomalyDetector over REAL PostgreSQL through the real `ai_readonly` role. Rows are written through ai_admin
| (committed; see AiToolsFixture). Skips like P7-01 when the role/admin connection is not available.
*/

function loadDaily(array $rows): void
{
    AiToolsFixture::loadDaily($rows);
}

beforeEach(function () {
    $skip = AiToolsFixture::boot();

    if ($skip !== null) {
        $this->markTestSkipped($skip);
    }

    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'Asia/Tehran'));   // a Wednesday, mid-day: today's row is partial
});

afterEach(function () {
    AiToolsFixture::teardown();
});

it('finds the injected anomalies in real daily_metrics and ignores today\'s partial row', function () {
    $rows = AnomalySeries::make('2026-06-01', '2026-10-14', [
        '2026-10-07' => ['orders_count' => 130, 'net_revenue' => 26_000_000],
        '2026-10-14' => ['orders_count' => 1, 'net_revenue' => 200_000, 'customers_new' => 0, 'customers_repeat' => 1, 'customers_total' => 1, 'revenue' => 206_000, 'refunds' => 6_000],   // "today so far" — would look like a crash
    ]);
    loadDaily($rows);

    $report = app(AnomalyDetector::class)->report(14);
    $peaks = array_unique(array_map(fn ($e) => $e->peakDate, $report->events));

    expect($report->status)->toBe('ok')->and($report->asOf)->toBe('2026-10-13')->and($report->dataAgeDays)->toBe(0)
        ->and(array_values($peaks))->toBe(['2026-10-07'])
        ->and($report->events[0]->severity)->toBe('critical')->and($report->events[0]->direction)->toBe('up');
});

it('never analyses an incomplete day, even when it is the only newest row', function () {
    loadDaily(AnomalySeries::make('2026-06-01', '2026-10-14', ['2026-10-14' => ['orders_count' => 900, 'net_revenue' => 180_000_000]]));   // a "spike" that is just today

    $report = app(AnomalyDetector::class)->report(3);

    expect($report->asOf)->toBe('2026-10-13')->and(array_filter($report->events, fn ($e) => $e->to === '2026-10-14'))->toBe([]);
});

it('uses Tehran time for "yesterday": 21:00 UTC is already the next Tehran day', function () {
    loadDaily(AnomalySeries::make('2026-06-01', '2026-10-14'));
    $this->travelTo(CarbonImmutable::parse('2026-10-14 21:00:00', 'UTC'));   // 00:30 on Oct 15 in Tehran: Oct 14 is now complete

    expect(app(AnomalyDetector::class)->report(3)->asOf)->toBe('2026-10-14');
});

it('reports how stale the data is when the nightly chain has not run', function () {
    loadDaily(AnomalySeries::make('2026-06-01', '2026-10-08'));

    $report = app(AnomalyDetector::class)->report(7);

    expect($report->asOf)->toBe('2026-10-08')->and($report->dataAgeDays)->toBe(5);   // yesterday is Oct 13
});

it('says "insufficient_data" on a short history and "no_data" on an empty table', function () {
    expect(app(AnomalyDetector::class)->report(7)->status)->toBe('no_data');

    loadDaily(AnomalySeries::make('2026-10-01', '2026-10-13'));

    expect(app(AnomalyDetector::class)->report(7)->status)->toBe('insufficient_data');
});

it('serves get_anomalies through the ToolRegistry with real detections', function () {
    AiToolsFixture::seed();   // customers with planted fake PII, so the scan below is meaningful
    loadDaily(AnomalySeries::make('2026-06-01', '2026-10-13', ['2026-10-07' => ['orders_count' => 130, 'net_revenue' => 26_000_000]]));

    $r = app(ToolRegistry::class)->execute('get_anomalies', ['days' => 14, 'limit' => 5]);
    $json = $r->toModelJson();

    expect($r->failed())->toBeFalse()->and($r->result['status'])->toBe('ok')->and($r->result['anomalies'])->not->toBeEmpty()
        ->and($r->result['anomalies'][0]['severity'])->toBe('critical')->and($r->result['anomalies'][0]['peak_date']['iso'])->toBe('2026-10-07')
        ->and($r->result['anomalies'][0]['peak_date']['jalali'])->toMatch('/^1405\/07\/\d\d$/')
        ->and(count($r->result['anomalies']))->toBeLessThanOrEqual(5);

    foreach (AiToolsFixture::PII as $needle) {
        expect($json)->not->toContain($needle);
    }
});

it('scans three years of real daily_metrics quickly', function () {
    loadDaily(AnomalySeries::make('2023-10-01', '2026-10-13', ['2026-10-07' => ['orders_count' => 130, 'net_revenue' => 26_000_000]]));
    $t = microtime(true);

    $report = app(AnomalyDetector::class)->report(1100);
    $elapsed = microtime(true) - $t;

    expect($report->evaluated)->toBeGreaterThan(5000)->and($elapsed)->toBeLessThan(3.0)
        ->and(array_values(array_unique(array_map(fn ($e) => $e->peakDate, $report->events))))->toBe(['2026-10-07']);
});
