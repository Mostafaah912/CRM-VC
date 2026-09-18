<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/** The arch rules are only as good as the scanner — prove it detects and ignores correctly. */
beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/arch_scanner_'.uniqid().'.php';
});

afterEach(function () {
    @unlink($this->tmp);
});

it('detects a forbidden call in real code', function () {
    file_put_contents($this->tmp, "<?php\n\$x = 1;\nDB::raw(\$a);\n");

    expect(Scanner::violations([$this->tmp], ['/DB::raw\(/']))->toHaveCount(1)
        ->and(Scanner::violations([$this->tmp], ['/DB::raw\(/'])[0])->toContain(':3:');
});

it('ignores forbidden text inside comments and docblocks', function () {
    file_put_contents($this->tmp, "<?php\n// DB::raw(\$a)\n/** whereRaw(x) */\n/* dd(\$x) */\n");

    expect(Scanner::violations([$this->tmp], ['/DB::raw\(/', '/whereRaw\(/', '/\bdd\(/']))->toBeEmpty();
});

it('finds the app modules', function () {
    expect(Scanner::modules())->toContain('Core');
});
