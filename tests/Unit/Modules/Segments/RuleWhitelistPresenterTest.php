<?php

declare(strict_types=1);

use App\Modules\Segments\Support\RuleFieldWhitelist;
use App\Modules\Segments\Support\RuleWhitelistPresenter;

/*
| RuleWhitelistPresenter (P5-05) mirrors P5-01's whitelist for the frontend. These tests exist so a
| future field/operator added to the whitelist without a Persian label fails loudly here, in PHP,
| rather than silently rendering blank in the Rule Builder.
*/

it('lists every whitelisted field with a non-empty Persian label', function () {
    $all = [
        ...RuleFieldWhitelist::CUSTOMER_FIELDS,
        ...RuleFieldWhitelist::METRICS_FIELDS,
        ...RuleFieldWhitelist::BEHAVIOR_FIELDS,
    ];

    $fields = RuleWhitelistPresenter::toArray()['fields'];

    expect($fields)->toHaveCount(count($all));

    foreach ($fields as $field) {
        expect($field['label'])->not->toBe('')
            ->and(in_array($field['name'], $all, true))->toBeTrue();
    }
});

it('lists every whitelisted operator with a non-empty Persian label and a value shape', function () {
    $operators = RuleWhitelistPresenter::toArray()['operators'];

    expect($operators)->toHaveCount(20);

    foreach ($operators as $operator) {
        expect($operator['label'])->not->toBe('')
            ->and($operator['valueShape'])->toBeIn(['scalar', 'list', 'range', 'none', 'relative_days']);
    }
});

it('marks exactly the seven behavior operators as behaviorOnly', function () {
    $operators = RuleWhitelistPresenter::toArray()['operators'];
    $behaviorOnly = array_column(array_filter($operators, fn (array $o) => $o['behaviorOnly']), 'name');

    expect($behaviorOnly)->toEqualCanonicalizing([
        'bought_product', 'not_bought_product', 'bought_category',
        'not_bought_category', 'bought_variation', 'in_segment', 'not_in_segment',
    ]);
});

it('mirrors RuleValidator\'s structural limits exactly', function () {
    $limits = RuleWhitelistPresenter::toArray()['limits'];

    expect($limits)->toBe([
        'maxDepth' => 4,
        'maxNodes' => 100,
        'minChildren' => 1,
        'maxChildren' => 20,
        'maxListValues' => 200,
        'maxRelativeDays' => 3650,
    ]);
});

it('gives within_days_of_now the relative_days value shape, and only that operator', function () {
    $operators = RuleWhitelistPresenter::toArray()['operators'];
    $relativeDays = array_column(array_filter($operators, fn (array $o) => $o['valueShape'] === 'relative_days'), 'name');

    expect($relativeDays)->toBe(['within_days_of_now']);
});

it('never assigns the behavior group to a customer or metrics field', function () {
    $fields = RuleWhitelistPresenter::toArray()['fields'];
    $behaviorFields = array_column(array_filter($fields, fn (array $f) => $f['group'] === 'behavior'), 'name');

    expect($behaviorFields)->toEqualCanonicalizing(RuleFieldWhitelist::BEHAVIOR_FIELDS);
});
