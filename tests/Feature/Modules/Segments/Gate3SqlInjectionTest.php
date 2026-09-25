<?php

declare(strict_types=1);

use App\Modules\Segments\Exceptions\RuleValidationException;
use App\Modules\Segments\Services\RuleCompiler;
use Illuminate\Support\Facades\Schema;

/*
| GATE 3 (PRD §26/§17): "SQL-injection test on RuleCompiler must be green." Every classic payload
| below is used as a field name, an operator, a scalar value, a value inside "in"/"between", and a
| value buried in nested AND/OR groups. Each either (a) is rejected before any query touches the
| database — field/operator can never reach a column/method name — or (b) is bound as an ordinary
| parameter and never appears in the generated SQL string. Real PostgreSQL; nothing here is mocked.
*/

/** @return list<string> */
function classicInjectionPayloads(): array
{
    return [
        "' OR 1=1--",
        '; DROP TABLE customers; --',
        "' OR '1'='1",
        '1; DELETE FROM customers WHERE 1=1;--',
        "admin'--",
        "' UNION SELECT * FROM users--",
        "1' AND SLEEP(5)--",
        '"; DROP TABLE customers; --',
        "'; UPDATE customers SET metrics_dirty=true;--",
        "%' OR '1'='1",
        '1 OR 1=1',
        'customers.id',
    ];
}

it('rejects every classic payload used as a field name, before any query is built', function (string $payload) {
    expect(fn () => RuleCompiler::compile(['field' => $payload, 'operator' => '=', 'value' => 1]))
        ->toThrow(RuleValidationException::class);

    expect(Schema::hasTable('customers'))->toBeTrue();
})->with(classicInjectionPayloads());

it('rejects every classic payload used as an operator, before any query is built', function (string $payload) {
    expect(fn () => RuleCompiler::compile(['field' => 'total_orders', 'operator' => $payload, 'value' => 1]))
        ->toThrow(RuleValidationException::class);

    expect(Schema::hasTable('customers'))->toBeTrue();
})->with(classicInjectionPayloads());

it('binds every classic payload as a scalar value, never concatenating it into SQL', function (string $payload) {
    $query = RuleCompiler::compile(['field' => 'city', 'operator' => '=', 'value' => $payload]);

    expect($query->toSql())->not->toContain($payload)
        ->and($query->getBindings())->toContain($payload);

    $query->count();

    expect(Schema::hasTable('customers'))->toBeTrue();
})->with(classicInjectionPayloads());

it('binds every classic payload inside an "in" array, never concatenating it into SQL', function (string $payload) {
    $query = RuleCompiler::compile(['field' => 'city', 'operator' => 'in', 'value' => [$payload, 'تهران']]);

    expect($query->toSql())->not->toContain($payload)
        ->and($query->getBindings())->toContain($payload);

    $query->count();

    expect(Schema::hasTable('customers'))->toBeTrue();
})->with(classicInjectionPayloads());

it('binds every classic payload inside a "between" array, never concatenating it into SQL', function (string $payload) {
    $query = RuleCompiler::compile(['field' => 'city', 'operator' => 'between', 'value' => [$payload, 'ژ']]);

    expect($query->toSql())->not->toContain($payload)
        ->and($query->getBindings())->toContain($payload);

    $query->count();

    expect(Schema::hasTable('customers'))->toBeTrue();
})->with(classicInjectionPayloads());

it('binds a payload buried inside deeply nested AND/OR groups, never concatenating it into SQL', function (string $payload) {
    $rule = [
        'op' => 'AND',
        'children' => [
            ['field' => 'total_orders', 'operator' => '>=', 'value' => 1],
            ['op' => 'OR', 'children' => [
                ['field' => 'city', 'operator' => '=', 'value' => $payload],
                ['field' => 'rfm_segment', 'operator' => '=', 'value' => 'champion'],
            ]],
        ],
    ];

    $query = RuleCompiler::compile($rule);

    expect($query->toSql())->not->toContain($payload)
        ->and($query->getBindings())->toContain($payload);

    $query->count();

    expect(Schema::hasTable('customers'))->toBeTrue();
})->with(classicInjectionPayloads());

it('rejects a raw-SQL injection attempt disguised as a group operator', function () {
    expect(fn () => RuleCompiler::compile([
        'op' => 'AND; DROP TABLE customers;--',
        'children' => [['field' => 'total_orders', 'operator' => '=', 'value' => 1]],
    ]))->toThrow(RuleValidationException::class);

    expect(Schema::hasTable('customers'))->toBeTrue();
});
