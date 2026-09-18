<?php

declare(strict_types=1);

use App\Support\Exceptions\InvalidMoneyException;
use App\Support\Money;

it('formats a Toman amount with thousands separators and Persian digits', function () {
    expect(Money::format(403880))->toBe('۴۰۳٬۸۸۰ تومان');
});

it('formats zero correctly', function () {
    expect(Money::format(0))->toBe('۰ تومان');
});

it('formats a Toman amount without the currency suffix on request', function () {
    expect(Money::format(1250000, withSuffix: false))->toBe('۱٬۲۵۰٬۰۰۰');
});

it('converts ASCII digits to Persian digits', function () {
    expect(Money::toPersianDigits('1234567890'))->toBe('۱۲۳۴۵۶۷۸۹۰');
});

it('parses a plain integer string into Toman', function () {
    expect(Money::parseToman('403880'))->toBe(403880);
});

it('parses a thousands-separated Persian-digit string into Toman', function () {
    expect(Money::parseToman('۴۰۳٬۸۸۰'))->toBe(403880);
});

it('parses a string with the toman suffix and spaces', function () {
    expect(Money::parseToman('403,880 تومان'))->toBe(403880);
});

it('throws on a value containing a decimal point — money is never a float', function () {
    Money::parseToman('403880.50');
})->throws(InvalidMoneyException::class);

it('throws on a negative-looking non-numeric value', function () {
    Money::parseToman('not-a-number');
})->throws(InvalidMoneyException::class);

it('throws on an empty value', function () {
    Money::parseToman('');
})->throws(InvalidMoneyException::class);
