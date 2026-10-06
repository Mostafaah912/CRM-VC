<?php

declare(strict_types=1);

use App\Support\PersianText;

/*
| P6-14 phase 4: a city/province value as Woo sends it — trim + Arabic/Persian letter-shape fix
| (ي -> ی, ك -> ک), the same two letters PersonNameNormalizer already treats as typography, not
| identity (CLAUDE.md §2). Unlike PersonNameNormalizer (a comparison KEY), this is a display/storage
| VALUE: internal spaces, case and every other character are kept exactly as typed.
*/

it('fixes Arabic yeh (U+064A) to Persian yeh (U+06CC)', function () {
    expect(PersianText::fixLetterShapes("\u{064A}زد"))->toBe("\u{06CC}زد");
});

it('fixes Arabic kaf (U+0643) to Persian keh (U+06A9)', function () {
    expect(PersianText::fixLetterShapes("\u{0643}رج"))->toBe("\u{06A9}رج");
});

it('trims leading and trailing whitespace', function () {
    expect(PersianText::fixLetterShapes('  تهران  '))->toBe('تهران');
});

it('keeps internal spaces, unlike PersonNameNormalizer which strips them for comparison', function () {
    expect(PersianText::fixLetterShapes('بندر عباس'))->toBe('بندر عباس');
});

it('returns null for null, and empty string for blank input', function () {
    expect(PersianText::fixLetterShapes(null))->toBeNull()
        ->and(PersianText::fixLetterShapes('   '))->toBe('');
});

it('leaves an already-Persian value unchanged', function () {
    expect(PersianText::fixLetterShapes('کرمان'))->toBe('کرمان');
});
