<?php

declare(strict_types=1);

use App\Support\Digits;

it('turns Persian and Arabic-Indic digits into ASCII and leaves everything else alone', function (string $in, string $out) {
    expect(Digits::toAscii($in))->toBe($out);
})->with([
    'persian' => ['۱۴۰۵/۰۶/۲۹', '1405/06/29'],
    'arabic-indic' => ['١٤٠٥-٠٦-٢٩', '1405-06-29'],
    'mixed' => ['۱۴05/٠۶/29', '1405/06/29'],
    'ascii unchanged' => ['1405/06/29', '1405/06/29'],
    'text untouched' => ['علی ۱۲', 'علی 12'],
    'empty' => ['', ''],
]);
