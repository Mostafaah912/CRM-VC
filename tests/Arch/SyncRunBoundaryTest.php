<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P2-08 boundary: SyncEntityJob / SyncPageJob (thin) -> Sync\SyncService (owns the run, the frozen window and the
| cursor) -> OrderSyncService + RefundSyncService (P2-06/07). The rules that keep the sync correct are pinned here
| structurally, so a later edit cannot quietly break them: the cursor advances in ONE place, run counters are never
| incremented, jobs hold no logic, and the run tables are written only by SyncService.
*/

function syncRunFile(string $relative): string
{
    return Scanner::root().'/app/Modules/Sync/'.$relative;
}

/** @return list<string> */
function syncJobFiles(): array
{
    return Scanner::phpFiles(['app/Modules/Sync/Jobs']);
}

it('has exactly the two jobs P2-08 asks for, both unique and queued', function () {
    $names = array_map(fn (string $f) => basename($f, '.php'), syncJobFiles());
    sort($names);

    expect($names)->toBe(['SyncEntityJob', 'SyncPageJob']);

    foreach (syncJobFiles() as $file) {
        $code = Scanner::phpCode($file);
        expect($code)->toMatch('/\bimplements\b[^{]*\bShouldQueue\b/')
            ->and($code)->toMatch('/\bimplements\b[^{]*\bShouldBeUnique\b/')
            ->and($code)->toContain("onQueue('sync')");
    }
});

it('keeps the jobs free of business logic: no control flow, no models, no database — they call SyncService', function () {
    expect(Scanner::violations(syncJobFiles(), [
        '/\b(if|elseif|else|foreach|for|while|switch|match|try|catch)\b\s*[\(\{]/',
        '/App\\\\Modules\\\\Sync\\\\Models\\\\/',
        '/App\\\\Modules\\\\(?!Sync\\\\)/',
        '/\b(DB|Http|Redis|Cache|Log)::/',
        '/\bWooClient\b|OrderSyncService|RefundSyncService/',
    ]))->toBe([]);

    foreach (syncJobFiles() as $file) {
        expect(Scanner::phpCode($file))->toContain('SyncService');
    }
});

it('advances the stored cursor in exactly one place: SyncService, when a run completes', function () {
    $assignments = [];

    foreach (Scanner::phpFiles(['app']) as $file) {
        // The model's own casts() lists 'cursor_value' => 'immutable_datetime': a type, not a write.
        $isModel = str_starts_with(Scanner::relative($file), 'app/Modules/Sync/Models/');
        $patterns = $isModel ? ['/->cursor_value\s*=(?!=)/'] : ['/->cursor_value\s*=(?!=)/', '/[\'"]cursor_value[\'"]\s*=>/'];

        foreach (Scanner::violations([$file], $patterns) as $hit) {
            $assignments[] = Scanner::relative($file).' '.$hit;
        }
    }

    expect($assignments)->toHaveCount(1)
        ->and($assignments[0])->toStartWith('app/Modules/Sync/Services/SyncService.php');
});

it('never increments or decrements a stored counter in the sync run code — every counter is recomputed from rows and SET', function () {
    $files = [syncRunFile('Services/SyncService.php'), ...Scanner::phpFiles(['app/Modules/Sync/Models']), ...syncJobFiles()];

    expect(array_map('is_file', $files))->each->toBeTrue();
    expect(Scanner::violations($files, [
        '/->(increment|decrement|incrementQuietly|decrementQuietly)\s*\(/',
        '/\+=|-=|\+\+|--(?!>)/',
        '/\b(pages_processed|records_processed|records_failed|consecutive_failures)\b.{0,40}[\'"]?\s*(\+|-)\s*\d/',
        '/DB::raw|new\s+Expression|selectRaw|whereRaw|orderByRaw/',
    ]))->toBe([]);
});

it('lets only SyncService write the run tables', function () {
    $violations = [];

    foreach (Scanner::phpFiles(['app']) as $file) {
        $relative = Scanner::relative($file);

        if (str_starts_with($relative, 'app/Modules/Sync/Models/') || $relative === 'app/Modules/Sync/Services/SyncService.php') {
            continue;
        }

        foreach (Scanner::violations([$file], ['/Sync\\\\Models\\\\Sync(Job|Cursor|Log)\b/']) as $hit) {
            $violations[] = "{$relative}: {$hit}";
        }
    }

    expect($violations)->toBe([]);
});

it('keeps SyncService to Sync\'s own services and the run models — no Orders/Catalog/Customers models, no Woo credentials, no HTTP', function () {
    $file = syncRunFile('Services/SyncService.php');

    expect(is_file($file))->toBeTrue()
        ->and(Scanner::violations([$file], [
            '/App\\\\Modules\\\\(?!Sync\\\\)/',
            '/woo\.(key|secret|base_url)|WOO_|consumer_(key|secret)/i',
            '/\b(Http|Redis)::/',
            '/\bGuzzleHttp\\\\/',
            '/PhoneNormalizer|CustomerIdentityService/',
        ]))->toBe([]);
});

it('writes only through enums: no status, mode, entity or level literals in SyncService', function () {
    expect(Scanner::violations([syncRunFile('Services/SyncService.php')], [
        "/['\"](running|completed|failed|partial|incremental|full|webhook)['\"]/",
        "/['\"](debug|info|notice|warning|error|critical|alert|emergency)['\"]/",
        "/['\"]entity['\"]\s*(=>|,)\s*['\"]/",
    ]))->toBe([]);
});

it('never leaves a debug helper or a raw exception message in a run log: messages go through the scrubber', function () {
    $code = Scanner::phpCode(syncRunFile('Services/SyncService.php'));

    expect($code)->not->toMatch('/->getMessage\(\)/')
        ->and($code)->toContain('SafeErrorText');
});
