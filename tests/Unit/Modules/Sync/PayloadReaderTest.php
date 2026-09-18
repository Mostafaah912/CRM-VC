<?php

declare(strict_types=1);

use App\Modules\Sync\Exceptions\WooException;
use App\Modules\Sync\Exceptions\WooMappingException;
use App\Modules\Sync\Mappers\PayloadReader;
use Carbon\CarbonImmutable;

/*
| The typed reader every mapper builds on. One rule runs through all of it:
| a required field that is missing or malformed FAILS with the field path — it is never
| turned into null, 0 or '' — and the error names the type it saw, never the value (PII).
*/

function reader(array $data): PayloadReader
{
    return new PayloadReader($data, 'order');
}

function failsAt(callable $read, string $field): void
{
    try {
        $read();
    } catch (WooMappingException $e) {
        expect($e->field)->toBe($field)->and($e->entity)->toBe('order')->and($e->getMessage())->toContain($field);

        return;
    }

    test()->fail("Expected WooMappingException at {$field}");
}

// ------------------------------------------------------------------ ints

it('reads integers strictly', function () {
    expect(reader(['id' => 5001])->int('id'))->toBe(5001)
        ->and(reader(['id' => 0])->int('id'))->toBe(0);
});

it('does not coerce anything into an integer', function (mixed $value) {
    failsAt(fn () => reader(['id' => $value])->int('id'), 'id');
})->with(['numeric string' => ['5001'], 'float' => [5001.0], 'bool' => [true], 'null' => [null], 'array' => [[1]]]);

it('fails on a missing required integer', function () {
    failsAt(fn () => reader([])->int('id'), 'id');
});

it('requires ids to be at least 1', function (int $value) {
    failsAt(fn () => reader(['id' => $value])->positiveInt('id'), 'id');
})->with([[0], [-1]]);

it('reads a non-negative integer and rejects a negative one', function () {
    expect(reader(['q' => 0])->nonNegativeInt('q'))->toBe(0);
    failsAt(fn () => reader(['q' => -1])->nonNegativeInt('q'), 'q');
});

it('reads the magnitude of a signed integer (Woo sends refund quantities negative)', function () {
    expect(reader(['q' => -3])->absoluteInt('q'))->toBe(3)
        ->and(reader(['q' => 3])->absoluteInt('q'))->toBe(3);
    failsAt(fn () => reader(['q' => '3'])->absoluteInt('q'), 'q');
});

it('maps a Woo "no id" (0 / null / absent) to null and a real id to itself', function () {
    expect(reader(['product_id' => 0])->nullableId('product_id'))->toBeNull()
        ->and(reader(['product_id' => null])->nullableId('product_id'))->toBeNull()
        ->and(reader([])->nullableId('product_id'))->toBeNull()
        ->and(reader(['product_id' => 101])->nullableId('product_id'))->toBe(101);
});

it('does not let a malformed id pass as "no id"', function (mixed $value) {
    failsAt(fn () => reader(['product_id' => $value])->nullableId('product_id'), 'product_id');
})->with([['101'], [-4], [1.5], [true], [[]]]);

// --------------------------------------------------------------- strings

it('reads required strings', function () {
    expect(reader(['status' => 'completed'])->nonEmptyString('status'))->toBe('completed')
        ->and(reader(['first_name' => ''])->string('first_name'))->toBe('');
});

it('rejects a missing, blank or non-string required string', function (array $data, string $method) {
    failsAt(fn () => reader($data)->{$method}('status'), 'status');
})->with([
    'missing' => [[], 'nonEmptyString'],
    'blank' => [['status' => ''], 'nonEmptyString'],
    'int' => [['status' => 5], 'nonEmptyString'],
    'null' => [['status' => null], 'nonEmptyString'],
    'string() missing' => [[], 'string'],
    'string() int' => [['status' => 5], 'string'],
]);

it('maps an absent / null / empty optional string to null and keeps a real one untouched', function () {
    expect(reader([])->nullableString('sku'))->toBeNull()
        ->and(reader(['sku' => null])->nullableString('sku'))->toBeNull()
        ->and(reader(['sku' => ''])->nullableString('sku'))->toBeNull()
        ->and(reader(['sku' => ' SYN-1 '])->nullableString('sku'))->toBe(' SYN-1 ');
});

it('does not let a malformed optional string pass as null', function (mixed $value) {
    failsAt(fn () => reader(['sku' => $value])->nullableString('sku'), 'sku');
})->with([[5], [true], [['x']]]);

// ----------------------------------------------------------------- money

it('reads whole-Toman amounts from Woo strings, ints and integral floats as int', function (mixed $raw, int $expected) {
    $amount = reader(['total' => $raw])->money('total');

    expect($amount)->toBe($expected)->and(is_int($amount))->toBeTrue();
})->with([
    'string' => ['403880', 403880],
    'zero' => ['0', 0],
    'int' => [403880, 403880],
    'integral float' => [403880.0, 403880],
    'big' => ['11340000000', 11340000000],
]);

it('rejects anything that is not a clean non-negative whole amount', function (mixed $raw) {
    failsAt(fn () => reader(['total' => $raw])->money('total'), 'total');
})->with([
    'decimal string' => ['403880.5'],
    'decimal zeros' => ['403880.00'],
    'fractional float' => [403880.5],
    'negative string' => ['-5'],
    'negative int' => [-5],
    'word' => ['abc'],
    'empty' => [''],
    'null' => [null],
    'bool' => [true],
    'array' => [['1']],
    'nan' => [NAN],
    'leading zero (a phone number is not an amount)' => ['09121234567'],
    'leading zeros' => ['0403880'],
    'overflow' => ['99999999999999999999'],
    'padded' => [' 5'],
    'thousands separator' => ['403,880'],
]);

it('fails on a missing required amount', function () {
    failsAt(fn () => reader([])->money('total'), 'total');
});

it('treats an empty optional amount as null but never a malformed one', function () {
    expect(reader(['price' => ''])->nullableMoney('price'))->toBeNull()
        ->and(reader(['price' => null])->nullableMoney('price'))->toBeNull()
        ->and(reader([])->nullableMoney('price'))->toBeNull()
        ->and(reader(['price' => '250000'])->nullableMoney('price'))->toBe(250000);
    failsAt(fn () => reader(['price' => '2.5'])->nullableMoney('price'), 'price');
});

it('reads the magnitude of a signed refund amount', function () {
    expect(reader(['total' => '-100000'])->absoluteMoney('total'))->toBe(100000)
        ->and(reader(['total' => '100000'])->absoluteMoney('total'))->toBe(100000);
})->group('money');

it('rejects a malformed signed amount', function (mixed $raw) {
    failsAt(fn () => reader(['total' => $raw])->absoluteMoney('total'), 'total');
})->with([['--5'], ['5-'], ['-'], ['-1.5'], [-5], [null], ['-007']]);

// ----------------------------------------------------------------- dates

it('reads a Woo GMT timestamp as an immutable UTC instant', function () {
    $date = reader(['date_created_gmt' => '2026-05-10T08:30:00'])->date('date_created_gmt');

    expect($date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($date->getTimezone()->getName())->toBe('UTC')
        ->and($date->format('Y-m-d H:i:s'))->toBe('2026-05-10 08:30:00');
});

it('rejects a timestamp that is not exactly Y-m-d\TH:i:s or not a real date', function (mixed $raw) {
    failsAt(fn () => reader(['d' => $raw])->date('d'), 'd');
})->with([
    'space separated' => ['2026-05-10 08:30:00'],
    'with offset' => ['2026-05-10T08:30:00+03:30'],
    'with zulu' => ['2026-05-10T08:30:00Z'],
    'fractional' => ['2026-05-10T08:30:00.5'],
    'date only' => ['2026-05-10'],
    'impossible day' => ['2026-02-31T00:00:00'],
    'impossible hour' => ['2026-05-10T25:00:00'],
    'zero date' => ['0000-00-00T00:00:00'],
    'garbage' => ['yesterday'],
    'int' => [1778401800],
    'null' => [null],
    'empty' => [''],
]);

it('fails on a missing required date', function () {
    failsAt(fn () => reader([])->date('d'), 'd');
});

it('treats an absent or null optional date as null but never a malformed one', function () {
    expect(reader([])->nullableDate('d'))->toBeNull()
        ->and(reader(['d' => null])->nullableDate('d'))->toBeNull();
    failsAt(fn () => reader(['d' => 'garbage'])->nullableDate('d'), 'd');
    failsAt(fn () => reader(['d' => ''])->nullableDate('d'), 'd');
});

// --------------------------------------------------------------- nesting

it('reads nested objects and reports the full path of a failure inside them', function () {
    $billing = reader(['billing' => ['phone' => 5]])->object('billing');

    failsAt(fn () => $billing->nullableString('phone'), 'billing.phone');
});

it('reads a list of objects and reports the index of a failure inside it', function () {
    $items = reader(['line_items' => [['id' => 1], ['id' => 'x']]])->objects('line_items');

    expect($items)->toHaveCount(2)->and($items[0]->positiveInt('id'))->toBe(1);
    failsAt(fn () => $items[1]->positiveInt('id'), 'line_items.1.id');
});

it('rejects an object or list that is missing, of the wrong shape, or holds non-objects', function (array $data, string $method, string $field) {
    failsAt(fn () => reader($data)->{$method}($field), $field);
})->with([
    'object missing' => [[], 'object', 'billing'],
    'object is a string' => [['billing' => 'x'], 'object', 'billing'],
    'object is a list' => [['billing' => [1, 2]], 'object', 'billing'],
    'list missing' => [[], 'objects', 'line_items'],
    'list is a string' => [['line_items' => 'x'], 'objects', 'line_items'],
    'list is an object' => [['line_items' => ['a' => ['id' => 1]]], 'objects', 'line_items'],
]);

it('reports a non-object list element at its own index', function () {
    failsAt(fn () => reader(['line_items' => [['id' => 1], 5]])->objects('line_items'), 'line_items.1');
});

it('accepts an empty list of objects', function () {
    expect(reader(['line_items' => []])->objects('line_items'))->toBe([]);
});

// ------------------------------------------------------------ safety

it('never puts the offending value in the error, only its type', function () {
    $secretish = '09121234567-super-secret';

    try {
        reader(['total' => $secretish])->money('total');
    } catch (WooMappingException $e) {
        expect($e->getMessage())->not->toContain('09121234567')->not->toContain('super-secret')->toContain('string');

        return;
    }

    test()->fail('Expected WooMappingException');
});

it('raises an error callers can catch as a WooException', function () {
    expect(fn () => reader([])->int('id'))->toThrow(WooException::class);
});

it('does not mutate the payload it reads', function () {
    $data = ['id' => 1, 'billing' => ['phone' => '09000000001'], 'line_items' => [['id' => 2]]];
    $copy = $data;

    $r = reader($data);
    $r->int('id');
    $r->object('billing')->nullableString('phone');
    $r->objects('line_items');

    expect($data)->toBe($copy);
});
