<?php

declare(strict_types=1);

use App\Modules\Segments\Enums\RuleOperator;
use App\Modules\Segments\Exceptions\RuleWhitelistException;

/*
| PRD §17 JSON Rule Schema `Operator` union type, plus `within_days_of_now` (P5-07b — relative-date
| support, not in the original PRD list). Every listed operator string must parse to its enum case;
| anything else must throw, never fall through as a raw string (CLAUDE.md §2: enums for every
| status/level/stage, never bare strings).
*/

it('parses every whitelisted operator string to its enum case', function (string $value, RuleOperator $expected) {
    expect(RuleOperator::fromWhitelist($value))->toBe($expected);
})->with([
    ['=', RuleOperator::Equals],
    ['!=', RuleOperator::NotEquals],
    ['>', RuleOperator::GreaterThan],
    ['>=', RuleOperator::GreaterThanOrEqual],
    ['<', RuleOperator::LessThan],
    ['<=', RuleOperator::LessThanOrEqual],
    ['in', RuleOperator::In],
    ['not_in', RuleOperator::NotIn],
    ['between', RuleOperator::Between],
    ['is_null', RuleOperator::IsNull],
    ['is_not_null', RuleOperator::IsNotNull],
    ['contains', RuleOperator::Contains],
    ['bought_product', RuleOperator::BoughtProduct],
    ['not_bought_product', RuleOperator::NotBoughtProduct],
    ['bought_category', RuleOperator::BoughtCategory],
    ['not_bought_category', RuleOperator::NotBoughtCategory],
    ['bought_variation', RuleOperator::BoughtVariation],
    ['in_segment', RuleOperator::InSegment],
    ['not_in_segment', RuleOperator::NotInSegment],
    ['within_days_of_now', RuleOperator::WithinDaysOfNow],
]);

it('covers the whole PRD §17 Operator union plus the P5-07b relative-date addition, with no extra and no missing case', function () {
    $operators = [
        '=', '!=', '>', '>=', '<', '<=', 'in', 'not_in', 'between', 'is_null', 'is_not_null',
        'contains', 'bought_product', 'not_bought_product', 'bought_category',
        'not_bought_category', 'bought_variation', 'in_segment', 'not_in_segment',
        'within_days_of_now',
    ];

    expect(array_map(fn (RuleOperator $c) => $c->value, RuleOperator::cases()))
        ->toEqualCanonicalizing($operators);
});

it('throws RuleWhitelistException for an operator outside the whitelist, never silently ignoring it', function () {
    expect(fn () => RuleOperator::fromWhitelist('LIKE'))
        ->toThrow(RuleWhitelistException::class, "Operator 'LIKE' is not in the Segments rule whitelist.");
});

it('rejects a raw-SQL injection attempt disguised as an operator', function () {
    $malicious = '= 1; DROP TABLE customers; --';

    expect(fn () => RuleOperator::fromWhitelist($malicious))->toThrow(RuleWhitelistException::class);

    try {
        RuleOperator::fromWhitelist($malicious);
    } catch (RuleWhitelistException $e) {
        expect($e->reason)->toBe(RuleWhitelistException::INVALID_OPERATOR);
    }
});

it('rejects an empty operator string', function () {
    expect(fn () => RuleOperator::fromWhitelist(''))->toThrow(RuleWhitelistException::class);
});

it('is case-sensitive: an uppercased known operator is still rejected', function () {
    expect(fn () => RuleOperator::fromWhitelist('IN'))->toThrow(RuleWhitelistException::class);
});
