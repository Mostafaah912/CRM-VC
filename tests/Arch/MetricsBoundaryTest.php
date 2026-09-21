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

/**
 * P4-02: ChurnThresholdService's percentile SQL is a fixed heredoc — no `$` ever reaches it.
 * Uses Scanner::root() rather than base_path(): tests/Arch is not bound to the Feature/Integration
 * TestCase (tests/Pest.php), so the app container isn't guaranteed booted when this file runs alone.
 */
it('churn threshold service has no raw string interpolation in sql', function () {
    $content = file_get_contents(Scanner::root().'/app/Modules/Metrics/Services/ChurnThresholdService.php');
    expect($content)->not->toMatch('/(?:whereRaw|selectRaw|DB::raw|DB::statement)\([^;]*\$[^;]*\)/s');
});

/** P4-03: the CASE in mapSegments() must check cant_lose (specific) before lost (general), PRD §12. */
it('rfm calculator segment mapping has cant_lose before lost', function () {
    $content = file_get_contents(Scanner::root().'/app/Modules/Metrics/Services/RfmCalculator.php');
    $cantLosePos = strpos($content, 'cant_lose');
    $lostPos = strrpos($content, "'lost'");
    expect($cantLosePos)->toBeLessThan($lostPos);
});

/** P4-04: margin_rate/horizon_years always come from config, never a hardcoded literal (CLAUDE.md §3/§12). */
it('clv calculator reads margin and horizon from config not hardcoded', function () {
    $content = file_get_contents(Scanner::root().'/app/Modules/Metrics/Services/ClvCalculator.php');
    expect($content)->not->toContain('0.17');
    expect($content)->not->toContain('0.20');
    expect($content)->not->toContain('2.0');
    expect($content)->toContain("config('metrics.margin_rate')");
    expect($content)->toContain("config('metrics.horizon_years')");
});

/** P4-05: churn_reason is built only from recency_days/purchase_cycle_days/p75 — never a name or phone column. */
it('churn reason never references customer name or phone columns', function () {
    $content = file_get_contents(Scanner::root().'/app/Modules/Metrics/Services/ChurnCalculator.php');
    expect($content)->not->toContain('billing_phone');
    expect($content)->not->toContain('full_name');
    expect($content)->not->toContain('phone');
});

it('never puts a customer\'s name or phone in error_message or a log line', function () {
    expect(Scanner::violations(Scanner::phpFiles(['app/Modules/Metrics']), [
        '/error_message/i',
        '/\b(Log|logger)::|->(info|warning|error|debug|notice)\s*\(/',
        '/display_name|phone_normalized|phone_masked|phone_raw_last/i',
    ]))->toBe([]);
});
