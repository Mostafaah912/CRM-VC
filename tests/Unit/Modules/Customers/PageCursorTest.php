<?php

declare(strict_types=1);

use App\Modules\Customers\Support\PageCursor;
use Carbon\CarbonImmutable;

/*
| P3-05 — PageCursor: the ONE place a page position becomes an opaque string and back. URL-safe base64 of a JSON object with exactly the
| keys of the expected shape, decoded strictly. Pure (no Laravel, no database). The timeline (P3-04), orders, products and notes
| pages all use it, each with its own shape.
*/

const PC_ORDERS = ['ordered_at' => 'instant', 'id' => 'id'];
const PC_PRODUCTS = ['last_ordered_at' => 'instant', 'name' => 'text'];

function pcInstant(string $utc = '2026-09-20 10:00:00'): CarbonImmutable
{
    return CarbonImmutable::parse($utc, 'UTC');
}

/** A cursor built by hand, so a test can make it malformed in exactly one way. */
function pcRaw(mixed $data): string
{
    return rtrim(strtr(base64_encode(is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
}

// ================================================================== round trip

it('round-trips an instant and an id', function (string $utc, int $id) {
    $position = PageCursor::decode(PageCursor::encode(['ordered_at' => pcInstant($utc), 'id' => $id]), PC_ORDERS);

    expect($position->instant('ordered_at')->equalTo(pcInstant($utc)))->toBeTrue()
        ->and($position->id('id'))->toBe($id);
})->with([['2026-09-20 10:00:00', 12345], ['2026-03-20 20:30:00', 1], ['2025-01-01 00:00:00', PHP_INT_MAX], ['2024-02-29 23:59:59', 7]]);

it('round-trips a text position exactly — Persian, quotes, backslashes, slashes and SQL-looking text are data, never changed', function (string $name) {
    $position = PageCursor::decode(PageCursor::encode(['last_ordered_at' => pcInstant(), 'name' => $name]), PC_PRODUCTS);

    expect($position->text('name'))->toBe($name);
})->with([
    'persian' => ['پیراهن مردانه آستین بلند'],
    'zero-width and RTL marks' => ["پیراهن\u{200C}مردانه\u{200F}"],
    'quotes and backslash' => ['a "quoted" \\ name'],
    'slashes' => ['a/b\\c//d'],
    'sql-looking' => ["x'; DROP TABLE customers;--"],
    'emoji' => ['coat 🧥'],
    'empty' => [''],
    'the longest a product name can be' => [str_repeat('پ', 250)],
]);

it('encodes to URL-safe base64 without padding — safe in a query string as it is', function () {
    for ($i = 0; $i < 300; $i++) {
        $cursor = PageCursor::encode(['last_ordered_at' => pcInstant(), 'name' => "محصول شماره {$i} ؟ ~ > ?"]);

        expect($cursor)->toMatch('/^[A-Za-z0-9_-]+$/');
    }
});

it('exercises the URL-safe alphabet for real: some Persian names need "-" or "_" in their cursor, and still round-trip', function () {
    $needed = 0;

    for ($i = 0; $i < 300; $i++) {
        $name = "محصول ؟؟ {$i} ~~ >>";
        $cursor = PageCursor::encode(['last_ordered_at' => pcInstant(), 'name' => $name]);
        $needed += (int) (str_contains($cursor, '-') || str_contains($cursor, '_'));

        expect(PageCursor::decode($cursor, PC_PRODUCTS)->text('name'))->toBe($name);
    }

    // If this were 0 the alphabet swap would be untestable, as it was for the id-only timeline cursor.
    expect($needed)->toBeGreaterThan(0);
});

it('writes the instant in UTC whatever timezone it carries', function () {
    $tehran = CarbonImmutable::parse('2026-09-20 13:30:00', 'Asia/Tehran');

    expect(PageCursor::encode(['ordered_at' => $tehran, 'id' => 5]))->toBe(PageCursor::encode(['ordered_at' => pcInstant(), 'id' => 5]));
});

it('encodes exactly the JSON of the position, keys in the order given', function () {
    $cursor = PageCursor::encode(['ordered_at' => pcInstant(), 'id' => 42]);
    $json = base64_decode(strtr($cursor, '-_', '+/'), true);

    expect(json_decode((string) $json, true))->toBe(['ordered_at' => '2026-09-20T10:00:00Z', 'id' => 42]);
});

// ================================================================== strict decoding

it('rejects a cursor that is not exactly the expected shape', function (string $cursor) {
    PageCursor::decode($cursor, PC_ORDERS);
})->throws(InvalidArgumentException::class)->with([
    'empty' => [''],
    'not base64' => ['!!!not-base64!!!'],
    'base64 of text' => [pcRaw('hello')],
    'a JSON scalar' => [pcRaw('42')],
    'a JSON list' => [pcRaw('[1,2]')],
    'missing id' => [pcRaw(['ordered_at' => '2026-09-20T10:00:00Z'])],
    'missing instant' => [pcRaw(['id' => 1])],
    'an extra key' => [pcRaw(['ordered_at' => '2026-09-20T10:00:00Z', 'id' => 1, 'customer_id' => 9])],
    'keys in the wrong order' => [pcRaw(['id' => 1, 'ordered_at' => '2026-09-20T10:00:00Z'])],
    'the products shape given to the orders decoder' => [pcRaw(['last_ordered_at' => '2026-09-20T10:00:00Z', 'name' => 'x'])],
    'id as a string' => [pcRaw(['ordered_at' => '2026-09-20T10:00:00Z', 'id' => '1'])],
    'id as a float' => [pcRaw(['ordered_at' => '2026-09-20T10:00:00Z', 'id' => 1.5])],
    'id zero' => [pcRaw(['ordered_at' => '2026-09-20T10:00:00Z', 'id' => 0])],
    'negative id' => [pcRaw(['ordered_at' => '2026-09-20T10:00:00Z', 'id' => -3])],
    'instant not ISO' => [pcRaw(['ordered_at' => 'yesterday', 'id' => 1])],
    'instant with an offset instead of Z' => [pcRaw(['ordered_at' => '2026-09-20T10:00:00+03:30', 'id' => 1])],
    'instant that is not a real date' => [pcRaw(['ordered_at' => '2026-02-31T10:00:00Z', 'id' => 1])],
    'instant as a number' => [pcRaw(['ordered_at' => 1790000000, 'id' => 1])],
    'nested value' => [pcRaw(['ordered_at' => '2026-09-20T10:00:00Z', 'id' => [1]])],
]);

it('rejects a text position that is not a string, or is longer than a product name can be', function (mixed $name) {
    PageCursor::decode(pcRaw(['last_ordered_at' => '2026-09-20T10:00:00Z', 'name' => $name]), PC_PRODUCTS);
})->throws(InvalidArgumentException::class)->with([
    'a number' => [7],
    'null' => [null],
    'a list' => [['x']],
    'too long' => [str_repeat('x', 256)],
]);

it('rejects a cursor longer than the limit, and lets the caller choose the limit', function () {
    $cursor = PageCursor::encode(['last_ordered_at' => pcInstant(), 'name' => str_repeat('x', 200)]);

    expect(fn () => PageCursor::decode($cursor, PC_PRODUCTS, 100))->toThrow(InvalidArgumentException::class)
        ->and(PageCursor::decode($cursor, PC_PRODUCTS)->text('name'))->toBe(str_repeat('x', 200));
});

it('never turns a hostile cursor into anything but an exception or plain values', function () {
    $hostile = pcRaw(['ordered_at' => "2026-09-20T10:00:00Z'; DROP TABLE customers;--", 'id' => 1]);

    expect(fn () => PageCursor::decode($hostile, PC_ORDERS))->toThrow(InvalidArgumentException::class);
});

it('refuses to hand back a value under the wrong kind', function () {
    $position = PageCursor::decode(PageCursor::encode(['ordered_at' => pcInstant(), 'id' => 3]), PC_ORDERS);

    expect(fn () => $position->id('ordered_at'))->toThrow(LogicException::class)
        ->and(fn () => $position->instant('id'))->toThrow(LogicException::class)
        ->and(fn () => $position->text('id'))->toThrow(LogicException::class)
        ->and(fn () => $position->id('nope'))->toThrow(LogicException::class);
});
