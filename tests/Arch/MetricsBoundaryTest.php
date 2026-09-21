<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P4-01 boundary: Metrics services are the Rule 7 exception (ArchitectureTest, "confines raw SQL to
| migrations, the Metrics/Analytics modules..."), so they may use raw SQL freely — but a raw fragment
| here must still never be built from a variable that isn't a fixed, pre-checked literal (never
| request/rule input, unlike Segments where CLAUDE.md bans raw SQL outright).
*/

it('never puts a Doctrine-style bound user value straight into a Metrics service\'s raw SQL string', function () {
    $files = Scanner::phpFiles(['app/Modules/Metrics/Services']);

    expect($files)->not->toBeEmpty()
        // A raw SQL string built by directly concatenating a request/model attribute is the injection shape
        // this guards against; a config-selected fixed literal (see BaseAggregateService) is not this pattern.
        ->and(Scanner::violations($files, [
            '/\.\s*\$request->/',
            '/\{\$request->/',
            '/\.\s*\$\w+->input\(/',
        ]))->toBe([]);
});

/** P4-02: ChurnThresholdService's percentile SQL is a fixed heredoc — no `$` ever reaches it. */
it('churn threshold service has no raw string interpolation in sql', function () {
    $content = file_get_contents(
        base_path('app/Modules/Metrics/Services/ChurnThresholdService.php')
    );
    expect($content)->not->toMatch('/(?:whereRaw|selectRaw|DB::raw|DB::statement)\([^;]*\$[^;]*\)/s');
});

it('never puts a customer\'s name or phone in error_message or a log line', function () {
    expect(Scanner::violations(Scanner::phpFiles(['app/Modules/Metrics']), [
        '/error_message/i',
        '/\b(Log|logger)::|->(info|warning|error|debug|notice)\s*\(/',
        '/display_name|phone_normalized|phone_masked|phone_raw_last/i',
    ]))->toBe([]);
});
