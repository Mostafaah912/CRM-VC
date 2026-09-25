<?php

declare(strict_types=1);

use App\Modules\Segments\Exceptions\RuleValidationException;
use App\Modules\Segments\Services\RuleValidator;

/*
| PRD §17 JSON Rule Schema, validated structurally + against the P5-01 whitelist before
| RuleCompiler (P5-03) ever sees the rule. Test table from CLAUDE.md §24: every operator,
| nested AND/OR, field outside whitelist, injection attempts.
*/

/** @param array<mixed> $rule */
function assertValidRule(array $rule): void
{
    RuleValidator::validate($rule);
}

/** @param array<mixed> $rule */
function assertRuleFails(array $rule, string $reason): void
{
    try {
        RuleValidator::validate($rule);
        throw new RuntimeException('Expected RuleValidationException, none thrown.');
    } catch (RuleValidationException $e) {
        expect($e->reason)->toBe($reason);
    }
}

it('accepts a single leaf condition as the whole rule', function () {
    assertValidRule(['field' => 'rfm_segment', 'operator' => '=', 'value' => 'champion']);
})->throwsNoExceptions();

it('accepts a group with AND and nested OR children', function () {
    assertValidRule([
        'op' => 'AND',
        'children' => [
            ['field' => 'total_orders', 'operator' => '>=', 'value' => 2],
            [
                'op' => 'OR',
                'children' => [
                    ['field' => 'province', 'operator' => '=', 'value' => 'تهران'],
                    ['field' => 'churn_risk_level', 'operator' => '=', 'value' => 'high'],
                ],
            ],
        ],
    ]);
})->throwsNoExceptions();

it('accepts every whitelisted operator with a shape-appropriate value', function (string $field, string $operator, mixed $value) {
    assertValidRule(['field' => $field, 'operator' => $operator, 'value' => $value]);
})->with([
    ['total_orders', '=', 3],
    ['total_orders', '!=', 3],
    ['total_revenue', '>', 100000],
    ['total_revenue', '>=', 100000],
    ['recency_days', '<', 30],
    ['recency_days', '<=', 30],
    ['rfm_segment', 'in', ['champion', 'loyal']],
    ['rfm_segment', 'not_in', ['lost']],
    ['total_orders', 'between', [1, 5]],
    ['province', 'contains', 'تهر'],
    ['product', 'bought_product', 501],
    ['product', 'not_bought_product', 501],
    ['category', 'bought_category', 12],
    ['category', 'not_bought_category', 12],
    ['variation', 'bought_variation', 9001],
    ['segment', 'in_segment', [1, 2]],
    ['segment', 'not_in_segment', [3]],
])->throwsNoExceptions();

it('accepts is_null/is_not_null with no value key', function (string $operator) {
    assertValidRule(['field' => 'expected_next_order_at', 'operator' => $operator]);
})->with(['is_null', 'is_not_null'])->throwsNoExceptions();

it('accepts a numeric condition with a valid unit', function (string $unit) {
    assertValidRule(['field' => 'recency_days', 'operator' => '<', 'value' => 30, 'unit' => $unit]);
})->with(['days', 'toman'])->throwsNoExceptions();

it('rejects a field outside the P5-01 whitelist', function () {
    assertRuleFails(['field' => 'email', 'operator' => '=', 'value' => 'x@example.com'], RuleValidationException::INVALID_FIELD);
});

it('rejects a raw-SQL injection attempt disguised as a field name', function () {
    assertRuleFails(['field' => 'id; DROP TABLE customers; --', 'operator' => '=', 'value' => 1], RuleValidationException::INVALID_FIELD);
});

it('rejects an operator outside the P5-01 whitelist', function () {
    assertRuleFails(['field' => 'total_orders', 'operator' => 'LIKE', 'value' => 1], RuleValidationException::INVALID_OPERATOR);
});

it('rejects a raw-SQL injection attempt disguised as an operator', function () {
    assertRuleFails(['field' => 'total_orders', 'operator' => '= 1; DROP TABLE customers; --', 'value' => 1], RuleValidationException::INVALID_OPERATOR);
});

it('rejects a behavior-only operator used on a non-behavior field', function () {
    assertRuleFails(['field' => 'total_orders', 'operator' => 'bought_product', 'value' => 501], RuleValidationException::OPERATOR_NOT_ALLOWED_FOR_FIELD);
});

it('rejects a comparison operator used on a behavior field', function () {
    assertRuleFails(['field' => 'product', 'operator' => '=', 'value' => 501], RuleValidationException::OPERATOR_NOT_ALLOWED_FOR_FIELD);
});

it('rejects an unknown group operator', function () {
    assertRuleFails(['op' => 'XOR', 'children' => [['field' => 'total_orders', 'operator' => '=', 'value' => 1]]], RuleValidationException::INVALID_GROUP_OPERATOR);
});

it('rejects a group with zero children', function () {
    assertRuleFails(['op' => 'AND', 'children' => []], RuleValidationException::INVALID_CHILDREN_COUNT);
});

it('rejects a group with more than 20 children', function () {
    $children = [];

    for ($i = 0; $i < 21; $i++) {
        $children[] = ['field' => 'total_orders', 'operator' => '=', 'value' => $i];
    }

    assertRuleFails(['op' => 'AND', 'children' => $children], RuleValidationException::INVALID_CHILDREN_COUNT);
});

it('accepts a group at exactly 20 children', function () {
    $children = [];

    for ($i = 0; $i < 20; $i++) {
        $children[] = ['field' => 'total_orders', 'operator' => '=', 'value' => $i];
    }

    assertValidRule(['op' => 'AND', 'children' => $children]);
})->throwsNoExceptions();

it('rejects a rule nested deeper than 4 levels', function () {
    // depth 5: Group -> Group -> Group -> Group -> Group(leaf condition inside)
    $rule = ['field' => 'total_orders', 'operator' => '=', 'value' => 1];

    for ($i = 0; $i < 5; $i++) {
        $rule = ['op' => 'AND', 'children' => [$rule]];
    }

    assertRuleFails($rule, RuleValidationException::DEPTH_EXCEEDED);
});

it('accepts a rule at exactly 4 levels deep', function () {
    $rule = ['field' => 'total_orders', 'operator' => '=', 'value' => 1];

    for ($i = 0; $i < 4; $i++) {
        $rule = ['op' => 'AND', 'children' => [$rule]];
    }

    assertValidRule($rule);
})->throwsNoExceptions();

it('rejects a rule with more than 100 total nodes', function () {
    $children = [];

    for ($i = 0; $i < 20; $i++) {
        $children[] = [
            'op' => 'OR',
            'children' => array_fill(0, 6, ['field' => 'total_orders', 'operator' => '=', 'value' => 1]),
        ];
    }

    assertRuleFails(['op' => 'AND', 'children' => $children], RuleValidationException::TOO_MANY_NODES);
});

it('rejects an "in" list longer than 200 values', function () {
    assertRuleFails(['field' => 'rfm_segment', 'operator' => 'in', 'value' => range(1, 201)], RuleValidationException::VALUE_LIST_TOO_LARGE);
});

it('accepts an "in" list at exactly 200 values', function () {
    assertValidRule(['field' => 'total_orders', 'operator' => 'in', 'value' => range(1, 200)]);
})->throwsNoExceptions();

it('rejects "between" without exactly two values', function () {
    assertRuleFails(['field' => 'total_orders', 'operator' => 'between', 'value' => [1]], RuleValidationException::INVALID_VALUE_SHAPE);
});

it('rejects "in" with a non-array value', function () {
    assertRuleFails(['field' => 'rfm_segment', 'operator' => 'in', 'value' => 'champion'], RuleValidationException::INVALID_VALUE_SHAPE);
});

it('rejects a scalar operator given an array value', function () {
    assertRuleFails(['field' => 'total_orders', 'operator' => '=', 'value' => [1, 2]], RuleValidationException::INVALID_VALUE_SHAPE);
});

it('rejects is_null carrying an unexpected value', function () {
    assertRuleFails(['field' => 'expected_next_order_at', 'operator' => 'is_null', 'value' => 'x'], RuleValidationException::INVALID_VALUE_SHAPE);
});

it('rejects an invalid unit', function () {
    assertRuleFails(['field' => 'recency_days', 'operator' => '<', 'value' => 30, 'unit' => 'weeks'], RuleValidationException::INVALID_UNIT);
});

it('rejects a node that is neither a Group nor a Condition', function () {
    assertRuleFails(['foo' => 'bar'], RuleValidationException::INVALID_STRUCTURE);
});

it('rejects an empty rule array', function () {
    assertRuleFails([], RuleValidationException::INVALID_STRUCTURE);
});

it('carries a Persian message on every exception', function () {
    try {
        RuleValidator::validate(['field' => 'email', 'operator' => '=', 'value' => 'x']);
        expect(false)->toBeTrue('Expected exception');
    } catch (RuleValidationException $e) {
        expect($e->getMessage())->toMatch('/\p{Arabic}/u');
    }
});
