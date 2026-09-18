<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/** CLAUDE.md §2: enums for every status/level/stage — string-backed, typed, owned by exactly one module. */
function enumFiles(): array
{
    return array_values(array_filter(
        Scanner::phpFiles(['app']),
        fn (string $file) => preg_match('/^\s*enum\s+\w+/m', (string) file_get_contents($file)) === 1,
    ));
}

it('keeps every enum inside a module\'s Enums directory', function () {
    $misplaced = array_filter(enumFiles(), fn (string $f) => preg_match('#app/Modules/\w+/Enums/\w+\.php$#', $f) !== 1);

    expect(enumFiles())->not->toBeEmpty()
        ->and(array_map(fn ($f) => Scanner::relative($f), $misplaced))->toBeEmpty();
});

it('makes every enum string-backed and strictly typed', function () {
    $violations = [];

    foreach (enumFiles() as $file) {
        $code = (string) file_get_contents($file);

        if (preg_match('/^\s*enum\s+\w+\s*:\s*string\b/m', $code) !== 1) {
            $violations[] = Scanner::relative($file).': not a string-backed enum';
        }
        if (! str_contains($code, 'declare(strict_types=1);')) {
            $violations[] = Scanner::relative($file).': missing strict_types';
        }
    }

    expect($violations)->toBeEmpty();
});

it('never defines the same enum name in two modules (no duplicate enums)', function () {
    $byName = [];

    foreach (enumFiles() as $file) {
        $byName[basename($file, '.php')][] = Scanner::relative($file);
    }

    $duplicates = array_filter($byName, fn (array $files) => count($files) > 1);

    expect($duplicates)->toBeEmpty();
});

it('gives each enum its own value set — two enums must not be copies of each other', function () {
    // Same values, different meaning, different owning module (shared enums across modules are
    // forbidden): identity match confidence (Customers) vs CLV confidence (Metrics, PRD §09).
    $allowedTwins = ['ClvConfidence duplicates IdentityConfidence', 'IdentityConfidence duplicates ClvConfidence'];
    $seen = [];
    $copies = [];

    foreach (enumFiles() as $file) {
        preg_match_all("/case\s+\w+\s*=\s*'([^']+)'/", (string) file_get_contents($file), $m);
        $values = $m[1];
        sort($values);
        $key = implode('|', $values);

        if ($key !== '' && isset($seen[$key])) {
            $copies[] = basename($file, '.php').' duplicates '.$seen[$key];
        }
        $seen[$key] = basename($file, '.php');
    }

    expect(array_values(array_diff($copies, $allowedTwins)))->toBeEmpty();
});

it('defines no order-status enum: statuses are store-defined Woo data (config), not app code', function () {
    $orderStatusEnums = array_filter(enumFiles(), fn (string $f) => preg_match('/OrderStatus|WooStatus/i', basename($f)) === 1);

    expect($orderStatusEnums)->toBeEmpty();
});
