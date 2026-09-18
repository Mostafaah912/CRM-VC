<?php

declare(strict_types=1);

use App\Support\Exceptions\InvalidPhoneException;
use App\Support\PhoneNormalizer;

it('normalizes a local format with leading zero', function () {
    expect(PhoneNormalizer::normalize('09123456789'))->toBe('989123456789');
});

it('normalizes a national format without leading zero', function () {
    expect(PhoneNormalizer::normalize('9123456789'))->toBe('989123456789');
});

it('normalizes an international plus format', function () {
    expect(PhoneNormalizer::normalize('+989123456789'))->toBe('989123456789');
});

it('normalizes an international 00 dialing prefix format', function () {
    expect(PhoneNormalizer::normalize('00989123456789'))->toBe('989123456789');
});

it('normalizes a space separated format', function () {
    expect(PhoneNormalizer::normalize('0912 345 6789'))->toBe('989123456789');
});

it('normalizes a dash separated format', function () {
    expect(PhoneNormalizer::normalize('0912-345-6789'))->toBe('989123456789');
});

it('normalizes Persian digits', function () {
    expect(PhoneNormalizer::normalize('۰۹۱۲۳۴۵۶۷۸۹'))->toBe('989123456789');
});

it('normalizes Arabic-Indic digits', function () {
    expect(PhoneNormalizer::normalize('٠٩١٢٣٤٥٦٧٨٩'))->toBe('989123456789');
});

it('strips RTL and LTR mark characters', function () {
    $withRtlMark = "\u{200F}09123456789\u{200E}";

    expect(PhoneNormalizer::normalize($withRtlMark))->toBe('989123456789');
});

it('strips RTL marks mixed inside a spaced number', function () {
    $withMarks = "0912\u{200E} 345\u{200F} 6789";

    expect(PhoneNormalizer::normalize($withMarks))->toBe('989123456789');
});

it('throws for an empty value', function () {
    PhoneNormalizer::normalize('');
})->throws(InvalidPhoneException::class);

it('throws for a whitespace-only value', function () {
    PhoneNormalizer::normalize('   ');
})->throws(InvalidPhoneException::class);

it('throws for fewer than 10 significant digits', function () {
    PhoneNormalizer::normalize('912345');
})->throws(InvalidPhoneException::class);

it('throws for a number not starting with 9', function () {
    PhoneNormalizer::normalize('08123456789');
})->throws(InvalidPhoneException::class);

it('throws for a Tehran landline number (021...)', function () {
    PhoneNormalizer::normalize('02112345678');
})->throws(InvalidPhoneException::class);

it('throws for an Isfahan landline number (031...)', function () {
    PhoneNormalizer::normalize('03112345678');
})->throws(InvalidPhoneException::class);

it('throws for a non-Iranian international number', function () {
    PhoneNormalizer::normalize('0033123456789');
})->throws(InvalidPhoneException::class);

it('never returns a value that is not exactly 12 digits starting with 98', function () {
    $result = PhoneNormalizer::normalize('09123456789');

    expect($result)->toMatch('/^989\d{9}$/');
});
