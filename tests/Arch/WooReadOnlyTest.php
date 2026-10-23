<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/* D13 / CLAUDE.md §0: Phase 1 never writes to WooCommerce — and that is what makes a staging copy safe to point at the live store. */
it('has no HTTP write verb in any file of the Sync module that uses the HTTP client (the only code that talks to WooCommerce)', function () {
    // Eloquent ->delete()/->update() on local rows is fine; what is banned is a write verb on an HTTP request.
    $files = array_values(array_filter(
        Scanner::phpFiles(['app/Modules/Sync']),
        fn (string $f): bool => preg_match('/Http::|PendingRequest|Illuminate\\Http\\Client/', (string) file_get_contents($f)) === 1,
    ));

    expect($files)->not->toBeEmpty()
        ->and(Scanner::violations($files, ['/->(post|put|patch|delete)\s*\(/', '/Http::(post|put|patch|delete)\s*\(/']))->toBeEmpty();
});
