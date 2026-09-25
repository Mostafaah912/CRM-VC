<?php

declare(strict_types=1);

use App\Modules\Customers\Services\CustomerTimelineService;
use Carbon\CarbonImmutable;

/*
| P3-04 — the parts of CustomerTimelineService that need no database: the cursor (an opaque, URL-safe base64 of {happened_at, id})
| and the payload allowlist. Ordering, paging and the soft-delete check need rows and are in the Feature test.
*/

function ctsService(): CustomerTimelineService
{
    return new CustomerTimelineService;
}

function ctsCursor(mixed $payload): string
{
    return rtrim(strtr(base64_encode(is_string($payload) ? $payload : json_encode($payload)), '+/', '-_'), '=');
}

// ================================================================== cursor

it('round-trips a cursor: what is encoded decodes to the same instant and id', function (string $at, int $id) {
    $service = ctsService();
    $instant = CarbonImmutable::parse($at, 'UTC');

    [$decodedAt, $decodedId] = $service->decodeCursor($service->encodeCursor($instant, $id));

    expect($decodedAt->equalTo($instant))->toBeTrue()
        ->and($decodedId)->toBe($id);
})->with([
    'an ordinary event' => ['2026-09-20 10:00:00', 12345],
    'the smallest id' => ['2026-03-20 20:30:00', 1],
    'a large id' => ['2025-01-01 00:00:00', PHP_INT_MAX],
    'a leap day' => ['2024-02-29 23:59:59', 7],
]);

it('encodes the cursor as base64 JSON of exactly happened_at and id, in URL-safe form with no padding', function () {
    $cursor = ctsService()->encodeCursor(CarbonImmutable::parse('2026-09-20 10:00:00', 'UTC'), 42);
    $json = base64_decode(strtr($cursor, '-_', '+/').str_repeat('=', (4 - strlen($cursor) % 4) % 4), true);

    expect($cursor)->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and(json_decode((string) $json, true))->toBe(['happened_at' => '2026-09-20T10:00:00Z', 'id' => 42]);
});

it('encodes the instant in UTC whatever timezone it carries', function () {
    $service = ctsService();
    $tehran = CarbonImmutable::parse('2026-09-20 13:30:00', 'Asia/Tehran');
    $utc = CarbonImmutable::parse('2026-09-20 10:00:00', 'UTC');

    expect($service->encodeCursor($tehran, 5))->toBe($service->encodeCursor($utc, 5));
});

it('rejects a cursor that is not a well-formed {happened_at, id}', function (string $cursor) {
    ctsService()->decodeCursor($cursor);
})->throws(InvalidArgumentException::class)->with([
    'empty' => [''],
    'not base64' => ['!!!not-base64!!!'],
    'base64 of text, not JSON' => [ctsCursor('hello')],
    'JSON but a scalar' => [ctsCursor('42')],
    'JSON list' => [ctsCursor('[1,2]')],
    'missing id' => [ctsCursor(['happened_at' => '2026-09-20T10:00:00Z'])],
    'missing happened_at' => [ctsCursor(['id' => 1])],
    'an extra key' => [ctsCursor(['happened_at' => '2026-09-20T10:00:00Z', 'id' => 1, 'customer_id' => 9])],
    'id as a string' => [ctsCursor(['happened_at' => '2026-09-20T10:00:00Z', 'id' => '1'])],
    'id as a float' => [ctsCursor(['happened_at' => '2026-09-20T10:00:00Z', 'id' => 1.5])],
    'id zero' => [ctsCursor(['happened_at' => '2026-09-20T10:00:00Z', 'id' => 0])],
    'negative id' => [ctsCursor(['happened_at' => '2026-09-20T10:00:00Z', 'id' => -3])],
    'happened_at not ISO' => [ctsCursor(['happened_at' => 'yesterday', 'id' => 1])],
    'happened_at with an offset instead of Z' => [ctsCursor(['happened_at' => '2026-09-20T10:00:00+03:30', 'id' => 1])],
    'happened_at that is not a real date' => [ctsCursor(['happened_at' => '2026-02-31T10:00:00Z', 'id' => 1])],
    'happened_at as a number' => [ctsCursor(['happened_at' => 1790000000, 'id' => 1])],
    'far too long' => [str_repeat('A', 400)],
]);

it('never turns a hostile cursor into SQL: a decoded cursor is only ever a Carbon instant and an int', function () {
    $hostile = ctsCursor(['happened_at' => "2026-09-20T10:00:00Z'; DROP TABLE customers;--", 'id' => 1]);

    expect(fn () => ctsService()->decodeCursor($hostile))->toThrow(InvalidArgumentException::class);
});

// ================================================================== payload allowlist

it('passes only the allowlisted payload keys', function () {
    $payload = [
        'woo_order_id' => 12345, 'order_id' => 9, 'note' => 'called back', 'old_status' => 'processing', 'new_status' => 'completed',
        'email' => 'a@b.test', 'phone' => '989121234567', 'billing' => 'x', 'password' => 'p', 'token' => 't', 'customer_id' => 4,
    ];

    expect(ctsService()->filterPayload($payload))->toBe([
        'order_id' => 9, 'note' => 'called back', 'old_status' => 'processing', 'new_status' => 'completed', 'woo_order_id' => 12345,
    ]);
});

it('emits the allowed keys in allowlist order whatever order they were stored in (jsonb reorders keys)', function () {
    $shuffled = ['woo_order_id' => 1, 'new_status' => 'b', 'note' => 'n', 'order_id' => 2, 'old_status' => 'a'];

    expect(array_keys((array) ctsService()->filterPayload($shuffled)))->toBe(CustomerTimelineService::PAYLOAD_KEYS);
});

it('exposes exactly the five allowlisted keys, and no others', function () {
    expect(CustomerTimelineService::PAYLOAD_KEYS)->toBe(['order_id', 'note', 'old_status', 'new_status', 'woo_order_id']);
});

it('gives null — never an empty object — when nothing in the payload is allowed or there is no payload', function (mixed $payload) {
    expect(ctsService()->filterPayload($payload))->toBeNull();
})->with([
    'null' => [null],
    'empty array' => [[]],
    'only forbidden keys' => [['email' => 'a@b.test', 'phone' => '989121234567']],
    'a list, not an object' => [['woo_order_id', 'note']],
    'a JSON string' => ['{"woo_order_id":1}'],
    'a number' => [7],
]);

it('drops an allowed key whose value is not a plain scalar — an array or object cannot smuggle other keys through', function () {
    $payload = ['woo_order_id' => ['nested' => ['email' => 'a@b.test']], 'note' => ['x'], 'old_status' => 'processing'];

    expect(ctsService()->filterPayload($payload))->toBe(['old_status' => 'processing']);
});

it('keeps allowed scalars of every kind exactly as stored, including null and false', function () {
    expect(ctsService()->filterPayload(['order_id' => 0, 'note' => '', 'old_status' => null, 'new_status' => false]))
        ->toBe(['order_id' => 0, 'note' => '', 'old_status' => null, 'new_status' => false]);
});

it('does not match keys loosely: a different case or a padded key is not allowed', function () {
    expect(ctsService()->filterPayload(['Note' => 'x', 'NOTE' => 'y', ' note' => 'z', 'note ' => 'w', 'notes' => 'v']))->toBeNull();
});
