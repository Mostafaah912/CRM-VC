<?php

declare(strict_types=1);

use App\Modules\Customers\Services\PersonNameNormalizer;

/*
| P2-04 — what "the same last name" means. PRD §08 says a conflict needs the last name to differ
| with "similarity low" but defines no algorithm or threshold anywhere, so no fuzzy matching is
| invented: two names are compatible only when their canonical forms are identical. The canonical
| form removes what is typography, not identity (Arabic vs Persian letter shapes, ZWNJ, spacing,
| direction marks, tatweel, diacritics, Latin case). Anything else differing is a real difference
| and goes to human review — the safe direction, since a conflict never merges or blocks anything.
*/

function canonicalName(string $name): string
{
    return (new PersonNameNormalizer)->normalize($name);
}

it('treats typographic variants of the same name as identical', function (string $a, string $b) {
    expect(canonicalName($a))->toBe(canonicalName($b))->not->toBe('');
})->with([
    'surrounding spaces' => ['آزمایشی', '  آزمایشی  '],
    'arabic yeh and kaf' => ['كريمي', 'کریمی'],
    'alef maksura' => ['موسى', 'موسی'],
    'zwnj vs none vs space' => ["میرزا\u{200C}خانی", 'میرزاخانی'],
    'zwnj vs space' => ["میرزا\u{200C}خانی", 'میرزا خانی'],
    'direction marks' => ["\u{200F}نمونه\u{200E}", 'نمونه'],
    'tatweel' => ['احمـدی', 'احمدی'],
    'diacritics' => ['محمَّدی', 'محمدی'],
    'latin case' => ['Sample', 'SAMPLE'],
    'non-breaking space' => ["نمونه\u{00A0}دوم", 'نمونه دوم'],
    'repeated inner spaces' => ['نمونه   دوم', 'نمونه دوم'],
]);

it('keeps genuinely different names apart — no fuzzy matching', function (string $a, string $b) {
    expect(canonicalName($a))->not->toBe(canonicalName($b));
})->with([
    'different names' => ['آزمایشی', 'نمونه'],
    'one is a prefix of the other' => ['نمونه', 'نمونه‌ای'],
    'one extra word' => ['نمونه', 'نمونه دوم'],
    'one letter apart' => ['Sample', 'Sampla'],
    'alef vs alef-madda are different letters' => ['آزمایشی', 'ازمایشی'],
]);

it('reduces a name with no letters in it to the empty string', function (string $name) {
    expect(canonicalName($name))->toBe('');
})->with(['empty' => [''], 'spaces' => ['   '], 'zwnj only' => ["\u{200C}"], 'marks only' => ["\u{200F}\u{200E}"], 'tatweel only' => ['ـ']]);

it('is deterministic and idempotent', function () {
    $once = canonicalName('  كريمي‌زاده ');

    expect(canonicalName('  كريمي‌زاده '))->toBe($once)->and(canonicalName($once))->toBe($once);
});
