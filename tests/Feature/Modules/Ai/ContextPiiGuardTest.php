<?php

declare(strict_types=1);

use App\Modules\Ai\Context\ContextPiiException;
use App\Modules\Ai\Context\ContextPiiGuard;

/*
| P7-06 — the last line of defence on a finished context: strings and key names are scanned, numbers never are
| (a 10-digit revenue is not a phone number), and the exception names the JSON path, never the offending value.
*/

dataset('dirty payloads', [
    'mobile ascii' => [['segments' => [['name' => 'VIP 09121234567']]], 'mobile number', '$.segments.0.name'],
    'mobile +98' => [['x' => '+989121234567'], 'mobile number', '$.x'],
    'mobile 0098' => [['x' => '00989121234567'], 'mobile number', '$.x'],
    'mobile without zero' => [['x' => 'call 9121234567'], 'mobile number', '$.x'],
    'mobile persian digits' => [['x' => '۰۹۱۲۱۲۳۴۵۶۷'], 'mobile number', '$.x'],
    'mobile arabic-indic digits' => [['x' => '٠٩١٢١٢٣٤٥٦٧'], 'mobile number', '$.x'],
    'email' => [['a' => ['b' => 'ali.rezaei@example.com']], 'email address', '$.a.b'],
    'long persian digit run' => [['x' => 'کد ۱۲۳۴۵۶۷۸'], 'long digit run', '$.x'],
    'forbidden key customer_id' => [['rows' => [['customer_id' => 5]]], 'forbidden key', '$.rows.0.customer_id'],
    'forbidden key phone' => [['phone_number' => 'x'], 'forbidden key', '$.phone_number'],
    'forbidden key email' => [['Email' => null], 'forbidden key', '$.Email'],
    'forbidden key first_name' => [['k' => ['first_name' => 'x']], 'forbidden key', '$.k.first_name'],
]);

it('refuses a context carrying customer data, naming the path and kind but never the value', function (array $payload, string $kind, string $path) {
    try {
        (new ContextPiiGuard)->assertClean($payload);
        $this->fail('expected ContextPiiException');
    } catch (ContextPiiException $e) {
        expect($e->getMessage())->toContain($kind)->toContain($path)
            ->and($e->getMessage())->not->toContain('0912')->not->toContain('rezaei')->not->toContain('9121234567');
    }
})->with('dirty payloads');

it('does not mistake numbers or ordinary text for PII', function () {
    (new ContextPiiGuard)->assertClean([
        'revenue' => 9_123_456_789, 'orders' => 60, 'share' => 83.33, 'ratio' => 0.0912345678,
        'segment' => 'Big spenders', 'product' => 'پیراهن کتان — مدل ۲۴', 'jalali' => '1405/07/22', 'iso' => '2026-10-14', 'at' => '2026-10-14T05:10:00Z',
        'note' => 'order 123456 / sku 4455', 'persian_short' => 'تعداد ۱۲۳',
    ]);

    expect(true)->toBeTrue();
});

it('honours the configured denylist case-insensitively and ignores an empty entry', function () {
    config(['ai.context.pii_denylist' => ['', 'Rezaei', 'علی رضایی']]);

    expect(fn () => (new ContextPiiGuard)->assertClean(['p' => 'Customer REZAEI bought']))->toThrow(ContextPiiException::class, 'denylisted text')
        ->and(fn () => (new ContextPiiGuard)->assertClean(['p' => 'مشتری علی رضایی']))->toThrow(ContextPiiException::class)
        ->and(fn () => (new ContextPiiGuard)->assertClean(['p' => 'fine']))->not->toThrow(ContextPiiException::class);
});
