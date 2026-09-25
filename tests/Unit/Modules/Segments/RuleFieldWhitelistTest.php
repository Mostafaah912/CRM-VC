<?php

declare(strict_types=1);

use App\Modules\Segments\Enums\RuleFieldGroup;
use App\Modules\Segments\Exceptions\RuleWhitelistException;
use App\Modules\Segments\Support\RuleFieldWhitelist;

/*
| PRD §17 "Whitelist فیلد" table, verbatim. Every field it lists must resolve to its group; anything
| it does not list must throw, never pass through silently (CLAUDE.md §3 SQL-injection rule).
*/

it('resolves every customer field to the Customer group', function (string $field) {
    expect(RuleFieldWhitelist::group($field))->toBe(RuleFieldGroup::Customer);
    RuleFieldWhitelist::assertValid($field);
})->with(['province', 'city', 'status', 'lifecycle_stage', 'first_seen_at']);

it('resolves every metrics field to the Metrics group', function (string $field) {
    expect(RuleFieldWhitelist::group($field))->toBe(RuleFieldGroup::Metrics);
    RuleFieldWhitelist::assertValid($field);
})->with([
    'recency_days', 'total_orders', 'total_revenue', 'monetary', 'aov', 'frequency',
    'r_score', 'f_score', 'm_score', 'rfm_segment', 'churn_risk_level', 'churn_risk_score',
    'clv_historical', 'purchase_cycle_days', 'expected_next_order_at', 'cohort_month',
]);

it('resolves every behavior field to the Behavior group', function (string $field) {
    expect(RuleFieldWhitelist::group($field))->toBe(RuleFieldGroup::Behavior);
    RuleFieldWhitelist::assertValid($field);
})->with(['product', 'category', 'variation', 'segment']);

it('throws RuleWhitelistException for a field outside the whitelist, never silently ignoring it', function () {
    expect(fn () => RuleFieldWhitelist::group('email'))
        ->toThrow(RuleWhitelistException::class, "Field 'email' is not in the Segments rule whitelist.");
});

it('rejects a raw-SQL injection attempt disguised as a field name', function () {
    $malicious = 'id; DROP TABLE customers; --';

    expect(fn () => RuleFieldWhitelist::assertValid($malicious))->toThrow(RuleWhitelistException::class);

    try {
        RuleFieldWhitelist::group($malicious);
    } catch (RuleWhitelistException $e) {
        expect($e->reason)->toBe(RuleWhitelistException::INVALID_FIELD);
    }
});

it('rejects an empty field name', function () {
    expect(fn () => RuleFieldWhitelist::group(''))->toThrow(RuleWhitelistException::class);
});

it('is case-sensitive: a differently-cased known field is still rejected', function () {
    expect(fn () => RuleFieldWhitelist::group('Province'))->toThrow(RuleWhitelistException::class);
});
