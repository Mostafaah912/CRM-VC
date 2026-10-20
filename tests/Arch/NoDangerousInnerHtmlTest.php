<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/* T10 (PRD §21): React escapes by default; dangerouslySetInnerHTML would defeat that. */
it('never uses dangerouslySetInnerHTML in the React code', function () {
    $files = Scanner::files(['resources/js'], 'ts,tsx,js,jsx', ['wayfinder', 'actions', 'routes']);

    expect($files)->not->toBeEmpty();

    $hits = array_filter($files, fn (string $file): bool => str_contains((string) file_get_contents($file), 'dangerouslySetInnerHTML'));

    expect(array_values($hits))->toBeEmpty();
});
