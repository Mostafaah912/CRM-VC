<?php

declare(strict_types=1);

use App\Modules\Ai\Enums\AiInsightType;
use App\Modules\Analytics\Enums\AffinityLevel;
use App\Modules\Metrics\Enums\ChurnRiskLevel;
use App\Modules\Metrics\Enums\ClvConfidence;
use App\Modules\Metrics\Enums\MetricRunMode;
use App\Modules\Metrics\Enums\MetricRunStatus;
use App\Modules\Metrics\Enums\RfmSegment;
use App\Modules\Segments\Enums\SegmentType;
use App\Modules\Sync\Enums\SyncLogLevel;
use App\Modules\Sync\Enums\SyncMode;
use App\Modules\Sync\Enums\SyncStatus;

/** PRD §09/§12 value sets, spelled out so a silent rename or reorder fails loudly. */
dataset('p1_05_enums', [
    'MetricRunMode' => [MetricRunMode::class, ['full', 'dirty']],
    'MetricRunStatus' => [MetricRunStatus::class, ['running', 'completed', 'failed']],
    'RfmSegment (PRD §12 CASE order)' => [RfmSegment::class, ['champion', 'loyal', 'promising', 'new_customer', 'at_risk', 'cant_lose', 'hibernating', 'lost']],
    'ClvConfidence' => [ClvConfidence::class, ['low', 'medium', 'high']],
    'ChurnRiskLevel' => [ChurnRiskLevel::class, ['low', 'medium', 'high', 'lost']],
    'SegmentType' => [SegmentType::class, ['dynamic', 'static', 'manual']],
    'AffinityLevel' => [AffinityLevel::class, ['variation', 'product', 'category', 'basket']],
    'SyncMode' => [SyncMode::class, ['full', 'incremental', 'webhook']],
    'SyncStatus' => [SyncStatus::class, ['running', 'completed', 'failed', 'partial']],
    'SyncLogLevel' => [SyncLogLevel::class, ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']],
    'AiInsightType' => [AiInsightType::class, ['daily_brief', 'weekly_review', 'explain', 'anomaly', 'customer']],
]);

it('is a string-backed enum with exactly the PRD values, in order', function (string $enum, array $values) {
    expect(enum_exists($enum))->toBeTrue()
        ->and(array_map(fn (BackedEnum $case) => $case->value, $enum::cases()))->toBe($values);
})->with('p1_05_enums');

it('round-trips every value through from() and tryFrom()', function (string $enum, array $values) {
    foreach ($values as $value) {
        expect($enum::from($value)->value)->toBe($value)
            ->and($enum::tryFrom($value))->not->toBeNull();
    }
})->with('p1_05_enums');

it('rejects a value outside the set instead of coercing it', function (string $enum) {
    expect($enum::tryFrom('not-a-real-value'))->toBeNull()
        ->and($enum::tryFrom('FULL'))->toBeNull()
        ->and(fn () => $enum::from('not-a-real-value'))->toThrow(ValueError::class);
})->with(fn () => array_map(fn ($d) => [$d[0]], array_values(dataset_rows())));

it('serializes to its plain string value', function (string $enum, array $values) {
    foreach ($enum::cases() as $i => $case) {
        expect(json_encode(['v' => $case]))->toBe(json_encode(['v' => $values[$i]]));
    }
})->with('p1_05_enums');

it('keeps the RFM segments in the exact order the PRD CASE expression evaluates them', function () {
    // PRD §12: specific conditions (cant_lose) must be tested before general ones (lost).
    $order = array_map(fn (RfmSegment $s) => $s->value, RfmSegment::cases());

    expect(array_search('cant_lose', $order))->toBeLessThan(array_search('lost', $order));
});

function dataset_rows(): array
{
    return [
        [MetricRunMode::class], [MetricRunStatus::class], [RfmSegment::class], [ClvConfidence::class],
        [ChurnRiskLevel::class], [SegmentType::class], [AffinityLevel::class], [SyncMode::class],
        [SyncStatus::class], [SyncLogLevel::class], [AiInsightType::class],
    ];
}
