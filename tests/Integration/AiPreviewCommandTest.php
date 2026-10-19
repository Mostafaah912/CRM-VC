<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\AiToolsFixture;
use Tests\Support\AnomalySeries;

/*
| P7-07 — `hm:ai-preview`: the whole request for the REAL (fixture) data, printed, with no provider, no key and no network.
*/

beforeEach(function () {
    $skip = AiToolsFixture::boot();

    if ($skip !== null) {
        $this->markTestSkipped($skip);
    }

    Http::preventStrayRequests();
    config(['ai.providers.gemini.api_key' => null, 'ai.model' => null]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'Asia/Tehran'));
    AiToolsFixture::seed();
    AiToolsFixture::loadDaily(AnomalySeries::make('2026-06-01', '2026-10-13', ['2026-10-07' => ['orders_count' => 130, 'net_revenue' => 26_000_000]]));
});

afterEach(function () {
    AiToolsFixture::teardown();
});

it('prints the complete request — prompt, sizes, sections, system, user with the data, schema — with no key and no network', function () {
    $exit = Artisan::call('hm:ai-preview', ['type' => 'daily_brief']);
    $out = Artisan::output();

    expect($exit)->toBe(0)
        ->and($out)->toContain('PROMPT   analyst.daily.v4')->toContain('CONTEXT')->toContain('truncated: no')->toContain('redactions: 0')->toContain('FRESH    as_of 2026-10-13')->toContain('data_age_days 0')->toContain('stale_data: no')
        ->toContain('SECTIONS (bytes)')->toContain('kpis')->toContain('products_affinity')->toContain('input tokens')->toContain('maxOutputTokens 4500')
        ->toContain('=== SYSTEM ===')->toContain('تحلیلگر کسب‌وکار')->toContain('=== USER ===')->toContain('<data>')->toContain('"schema":"analyst.context.v1"')->toContain('</data>')
        ->toContain('=== OUTPUT SCHEMA ===')->toContain('"anomalies_explained"')->toContain('"data_quality_note"')
        ->and($out)->not->toContain('Rezaei')->not->toContain('09121234567')->not->toContain('ali.rezaei');

    Http::assertNothingSent();
});

it('can hide the data and show only the size table', function () {
    Artisan::call('hm:ai-preview', ['type' => 'weekly_review', '--no-data' => true]);
    $out = Artisan::output();

    expect($out)->toContain('PROMPT   analyst.weekly.v4')->toContain('[context JSON omitted: --no-data]')->not->toContain('"schema":"analyst.context.v1"');
});

it('refuses a type it cannot build', function () {
    expect(Artisan::call('hm:ai-preview', ['type' => 'explain']))->toBe(1)->and(Artisan::output())->toContain('type must be daily_brief or weekly_review');
});
