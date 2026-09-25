<?php

declare(strict_types=1);

use App\Support\PhoneMask;

/*
| A normalized phone as an operator without customers.view_full_phone sees it: the LAST FOUR digits stay, every other
| digit becomes an asterisk, and the length is preserved so nothing about the number's shape is invented or hidden.
*/

it('keeps only the last four digits and stars the rest, preserving the length', function (string $phone, string $expected) {
    expect(PhoneMask::mask($phone))->toBe($expected)->and(strlen(PhoneMask::mask($phone)))->toBe(strlen($phone));
})->with([
    'normalized mobile' => ['989121234567', '********4567'],
    'another' => ['989000000001', '********0001'],
    'five digits' => ['12345', '*2345'],
    'exactly four' => ['1234', '****'],
    'shorter than four' => ['12', '**'],
    'empty' => ['', ''],
]);

it('never reveals a digit outside the last four', function () {
    $masked = PhoneMask::mask('989121234567');

    expect($masked)->not->toContain('98')->not->toContain('912')->not->toContain('123')
        ->and(preg_match('/^\*{8}\d{4}$/', $masked))->toBe(1);
});
