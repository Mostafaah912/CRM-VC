<?php

declare(strict_types=1);

use App\Modules\Ai\Analyst\AnalystPeriods;
use App\Modules\Ai\Enums\AiInsightType;
use App\Modules\Ai\Services\AnalystReadiness;
use Carbon\CarbonImmutable;

/*
| P7-08 — the period an insight is about comes from the Asia/Tehran CALENDAR, never from the hour a job happens to run.
| 2026-10-14 is a Wednesday; 2026-10-17 is a Saturday; Iran has no DST, Tehran is UTC+03:30 all year.
*/

function periodsAt(string $when, string $tz = 'Asia/Tehran'): AnalystPeriods
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($when, $tz));

    return new AnalystPeriods;
}

afterEach(fn () => CarbonImmutable::setTestNow());

it('daily = yesterday, whatever time of day the job runs', function (string $when, string $tz, string $expected) {
    $p = periodsAt($when, $tz)->daily();

    expect($p->start)->toBe($expected)->and($p->end)->toBe($expected)->and($p->scope)->toBe("day:{$expected}");
})->with([
    'just after midnight' => ['2026-10-14 00:00:01', 'Asia/Tehran', '2026-10-13'],
    '07:00 (the schedule)' => ['2026-10-14 07:00:00', 'Asia/Tehran', '2026-10-13'],
    'one second before midnight' => ['2026-10-14 23:59:59', 'Asia/Tehran', '2026-10-13'],
    '23:30 Tehran given as UTC 20:00' => ['2026-10-13 20:00:00', 'UTC', '2026-10-12'],
    '00:30 Tehran given as UTC 21:00 (next Tehran day already)' => ['2026-10-13 21:00:00', 'UTC', '2026-10-13'],
    'a month boundary' => ['2026-11-01 08:00:00', 'Asia/Tehran', '2026-10-31'],
]);

it('daily carries the Jalali date for display', function () {
    $p = periodsAt('2026-10-14 07:00:00')->daily();

    expect($p->jalaliStart)->toBe('1405/07/21')->and($p->jalaliEnd)->toBe('1405/07/21');
});

it('weekly = the last COMPLETE Saturday-to-Friday week', function (string $when, string $tz, string $from, string $to) {
    $p = periodsAt($when, $tz)->weekly();

    expect($p->start)->toBe($from)->and($p->end)->toBe($to)->and($p->scope)->toBe("week:{$from}..{$to}")
        ->and(CarbonImmutable::parse($from)->dayOfWeek)->toBe(CarbonImmutable::SATURDAY)->and(CarbonImmutable::parse($to)->dayOfWeek)->toBe(CarbonImmutable::FRIDAY);
})->with([
    'Saturday 07:30 (the schedule) reviews the week that ended last night' => ['2026-10-17 07:30:00', 'Asia/Tehran', '2026-10-10', '2026-10-16'],
    'Saturday one second after midnight' => ['2026-10-17 00:00:01', 'Asia/Tehran', '2026-10-10', '2026-10-16'],
    'Friday one second before midnight: the week is not over yet' => ['2026-10-16 23:59:59', 'Asia/Tehran', '2026-10-03', '2026-10-09'],
    'a Wednesday' => ['2026-10-14 12:00:00', 'Asia/Tehran', '2026-10-03', '2026-10-09'],
    'Friday 20:30 UTC is already Saturday 00:00 in Tehran' => ['2026-10-16 20:30:00', 'UTC', '2026-10-10', '2026-10-16'],
    'Friday 20:29 UTC is still Friday 23:59 in Tehran' => ['2026-10-16 20:29:00', 'UTC', '2026-10-03', '2026-10-09'],
]);

it('weekly handles the Jalali new year: a week can straddle Esfand 29 and Farvardin', function () {
    // Nowruz 1406 is 2027-03-21: Saturday 2027-03-20 is the last day of 1405 (Esfand 29), Friday 2027-03-26 is Farvardin 6.
    $p = periodsAt('2027-03-27 07:30:00')->weekly();

    expect($p->start)->toBe('2027-03-20')->and($p->end)->toBe('2027-03-26')->and($p->jalaliStart)->toBe('1405/12/29')->and($p->jalaliEnd)->toBe('1406/01/06');
});

it('weekly across a year boundary of the Gregorian calendar', function () {
    $p = periodsAt('2027-01-02 07:30:00')->weekly();   // Saturday

    expect($p->start)->toBe('2026-12-26')->and($p->end)->toBe('2027-01-01');
});

it('picks the period by insight type and refuses the types it has no schedule for', function () {
    $periods = periodsAt('2026-10-17 07:30:00');

    expect($periods->for(AiInsightType::DailyBrief)->scope)->toBe('day:2026-10-16')->and($periods->for(AiInsightType::WeeklyReview)->scope)->toBe('week:2026-10-10..2026-10-16')
        ->and(fn () => $periods->for(AiInsightType::Explain))->toThrow(InvalidArgumentException::class)
        ->and(strlen($periods->weekly()->scope))->toBeLessThanOrEqual(40);   // ai_insights.scope is varchar(40)
});

describe('readiness', function () {
    beforeEach(function () {
        config([
            'ai.enabled' => true, 'ai.provider' => 'gemini', 'ai.model' => 'test-model', 'ai.providers.gemini.api_key' => 'k',
            'ai.pricing' => ['default' => ['input' => '0', 'output' => '0'], 'models' => ['test-model' => ['input' => '1', 'output' => '2']]], 'ai.budget.allow_unpriced' => false,
        ]);
    });

    it('is ready only when enabled, known provider, key, model and price are all set', function () {
        expect(app(AnalystReadiness::class)->generationProblem())->toBeNull()->and(app(AnalystReadiness::class)->callProblem())->toBeNull();
    });

    it('names the first thing that is missing, and AI_ENABLED comes first for generation but not for a ping', function (array $config, string $generation, ?string $call) {
        config($config);

        expect(app(AnalystReadiness::class)->generationProblem())->toBe($generation)->and(app(AnalystReadiness::class)->callProblem())->toBe($call);
    })->with([
        'switched off (the default)' => [['ai.enabled' => false], 'ai_disabled', null],
        'unknown provider' => [['ai.provider' => 'skynet'], 'unknown_provider', 'unknown_provider'],
        'no key' => [['ai.providers.gemini.api_key' => ''], 'missing_api_key', 'missing_api_key'],
        'blank key' => [['ai.providers.gemini.api_key' => '   '], 'missing_api_key', 'missing_api_key'],
        'no model' => [['ai.model' => null], 'missing_model', 'missing_model'],
        'unpriced model' => [['ai.pricing.models' => []], 'unpriced_model', 'unpriced_model'],
        'half-priced model' => [['ai.pricing.models.test-model.output' => '0'], 'unpriced_model', 'unpriced_model'],
    ]);

    it('is off by default in the shipped config', function () {
        expect((require base_path('config/ai.php'))['enabled'])->toBeFalse();
    });

    it('describes every code with the setting to change, and never mentions a key value', function () {
        config(['ai.providers.gemini.api_key' => 'SECRET-KEY-VALUE']);

        foreach (['ai_disabled', 'unknown_provider', 'missing_api_key', 'missing_model', 'unpriced_model'] as $code) {
            expect(app(AnalystReadiness::class)->describe($code))->not->toBe($code)->not->toContain('SECRET-KEY-VALUE');
        }
    });
});
