<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| CLAUDE.md §1/§3/§6/§8 enforced as tests. If one of these fails, the code —
| not the test — is wrong; a deliberate exception must be argued in
| ARCHITECTURE.md and added to the allow-list below with a reason.
*/

/** Facades/calls that mean "query the database" — never allowed in controllers or jobs. */
const DB_ACCESS_PATTERNS = [
    '/\bDB::/',
    '/\bDB\s*\(/',
    '/::(query|where|whereIn|find|findOrFail|create|firstOrCreate|updateOrCreate|insert|upsert|all|count|pluck|paginate)\s*\(/',
    '/->(where|whereIn|whereHas|join|groupBy|having|selectRaw|whereRaw|orderByRaw|update|delete|forceDelete|save|insert|upsert|increment|decrement|pluck|select|orderBy|latest|oldest)\s*\(/',
];

/** Rule 1 + 6: controllers validate, call ONE service, return Inertia — no queries. */
it('keeps controllers free of database access and business queries', function () {
    $files = Scanner::phpFiles(['app/Http/Controllers']);

    expect($files)->not->toBeEmpty()
        ->and(Scanner::violations($files, DB_ACCESS_PATTERNS))->toBeEmpty();
});

it('never lets a controller touch a module model directly (Controller -> Service -> Model)', function () {
    $files = Scanner::phpFiles(['app/Http/Controllers']);

    expect(Scanner::violations($files, ['/App\\\\Modules\\\\\w+\\\\Models\\\\/']))->toBeEmpty();
});

/** Rule 5: a Job resolves a Service and calls it. */
it('keeps jobs free of business logic', function () {
    $files = Scanner::phpFiles(['app/Jobs', ...array_map(fn ($m) => "app/Modules/{$m}/Jobs", Scanner::modules())]);

    expect(Scanner::violations($files, [
        ...DB_ACCESS_PATTERNS,
        '/App\\\\Modules\\\\\w+\\\\Models\\\\/',
    ]))->toBeEmpty();
});

/** Rule 2 + 10: modules talk to each other only through Services or Events. */
it('never lets a module use another module\'s model, only Customers\\Models\\Customer is shared', function () {
    $violations = [];

    foreach (Scanner::modules() as $module) {
        foreach (Scanner::phpFiles(["app/Modules/{$module}"]) as $file) {
            foreach (Scanner::violations([$file], ['/App\\\\Modules\\\\(\w+)\\\\Models\\\\(\w+)/']) as $hit) {
                preg_match('/App\\\\Modules\\\\(\w+)\\\\Models\\\\(\w+)/', $hit, $m);

                if ($m[1] !== $module && ! ($m[1] === 'Customers' && $m[2] === 'Customer')) {
                    $violations[] = $hit;
                }
            }
        }
    }

    expect($violations)->toBeEmpty();
});

it('only reaches into other modules through their Services or Events', function () {
    $violations = [];

    foreach (Scanner::modules() as $module) {
        foreach (Scanner::phpFiles(["app/Modules/{$module}"]) as $file) {
            foreach (Scanner::violations([$file], ['/App\\\\Modules\\\\(\w+)\\\\(\w+)\\\\(\w+)/']) as $hit) {
                preg_match('/App\\\\Modules\\\\(\w+)\\\\(\w+)\\\\(\w+)/', $hit, $m);

                $sharedCustomer = $m[1] === 'Customers' && $m[2] === 'Models' && $m[3] === 'Customer';

                if ($m[1] !== $module && ! in_array($m[2], ['Services', 'Events'], true) && ! $sharedCustomer) {
                    $violations[] = $hit;
                }
            }
        }
    }

    expect($violations)->toBeEmpty();
});

/** PRD §07 dependency table — a module may only depend on the modules listed for it. */
it('respects the module dependency table from the PRD', function () {
    $allowed = [
        'Core' => [],
        'Sync' => ['Core', 'Customers', 'Catalog', 'Orders'],
        'Customers' => ['Core'],
        'Catalog' => ['Core'],
        'Orders' => ['Core', 'Customers', 'Catalog'],
        'Metrics' => ['Core', 'Orders', 'Customers'],
        'Segments' => ['Core', 'Metrics', 'Customers'],
        'Analytics' => ['Core', 'Orders', 'Metrics', 'Catalog'],
        'Ai' => ['Core', 'Analytics', 'Metrics', 'Segments'],
    ];
    $violations = [];

    foreach (Scanner::modules() as $module) {
        expect($allowed)->toHaveKey($module);

        foreach (Scanner::phpFiles(["app/Modules/{$module}"]) as $file) {
            foreach (Scanner::violations([$file], ['/App\\\\Modules\\\\(\w+)\\\\/']) as $hit) {
                preg_match('/App\\\\Modules\\\\(\w+)\\\\/', $hit, $m);

                if ($m[1] !== $module && ! in_array($m[1], $allowed[$module], true)) {
                    $violations[] = $hit;
                }
            }
        }
    }

    expect($violations)->toBeEmpty();
});

/** Rule 3: Segments compiles user rules — raw SQL there is an injection hole. */
it('bans raw SQL in the Segments module outright', function () {
    $files = Scanner::phpFiles(['app/Modules/Segments']);

    expect(Scanner::violations($files, [
        '/\b(whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|fromRaw|joinRaw|whereColumnRaw)\s*\(/',
        '/\bDB::(raw|statement|select|unprepared|insert|update|delete)\b/',
        '/\bnew\s+(\\\\?Illuminate\\\\Database\\\\Query\\\\)?Expression\b/',
    ]))->toBeEmpty();
});

/**
 * Rule 7: raw SQL only where the architecture says heavy SQL lives.
 *
 * P3-07 amendment (ARCHITECTURE.md, "P3-07 — استثنای Rule 7 برای Aggregate فروش Catalog"): ONE extra file,
 * ProductListService.php, may use it — a store-wide grouped SUM/COUNT/MAX (every realized order line, not one customer's)
 * has no query-builder form without selectRaw/orderByRaw, and CLAUDE.md §3 bans a PHP loop over that much data. The module
 * itself stays banned; only this file, and only these raw fragments, are exempt.
 *
 * P5-04 amendment (ARCHITECTURE.md, "P5-04 — استثنای Rule 7 برای PostgresStatementTimeout"): a second file,
 * app/Support/PostgresStatementTimeout.php, may use it — PostgreSQL's `SET LOCAL statement_timeout` (PRD §17's
 * mandatory 5s cap on segment preview) has no bound-parameter/query-builder form at all (`SET x = $1` is a
 * Postgres syntax error, not a Laravel limitation), so a query-builder alternative does not exist to fall back
 * to. `$milliseconds` is always an app-controlled integer, never request input.
 */
it('confines raw SQL to migrations, the Metrics/Analytics modules, and Catalog\'s one product-sales aggregate', function () {
    $allowedPrefixes = ['app/Modules/Metrics/', 'app/Modules/Analytics/'];
    $allowedFiles = ['app/Modules/Catalog/Services/ProductListService.php', 'app/Support/PostgresStatementTimeout.php'];
    $files = array_filter(
        Scanner::phpFiles(['app', 'routes', 'config', 'bootstrap/app.php']),
        function (string $file) use ($allowedPrefixes, $allowedFiles): bool {
            $relative = Scanner::relative($file);

            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($relative, $prefix)) {
                    return false;
                }
            }

            return ! in_array($relative, $allowedFiles, true);
        },
    );

    expect(Scanner::violations(array_values($files), [
        '/\b(whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|fromRaw|joinRaw)\s*\(/',
        '/\bDB::(raw|statement|select|unprepared)\b/',
    ]))->toBeEmpty();
});

/** Rule 4: no debug helpers in committed code. */
it('has no debug helpers in PHP code', function () {
    $files = Scanner::phpFiles(['app', 'routes', 'config', 'database', 'bootstrap/app.php', 'resources/views']);

    expect(Scanner::violations($files, [
        '/(?<![\w>$:\\\\])(dd|dump|ray|var_dump|print_r|var_export|error_log|dumpandexit)\s*\(/',
        '/->(dd|dump|ray)\s*\(/',
    ]))->toBeEmpty();
});

it('has no console.log, debugger, or dangerouslySetInnerHTML in the frontend', function () {
    $files = Scanner::files(['resources/js'], 'ts,tsx', ['resources/js/actions', 'resources/js/routes', 'resources/js/wayfinder']);

    expect($files)->not->toBeEmpty()
        ->and(Scanner::violations($files, [
            '/\bconsole\.(log|debug)\s*\(/',
            '/\bdebugger\b/',
            '/dangerouslySetInnerHTML/',
        ], stripPhpComments: false))->toBeEmpty();
});

/** Rule 8: tests run on real PostgreSQL only. */
it('never configures SQLite for tests', function () {
    $phpunit = (string) file_get_contents(Scanner::root().'/phpunit.xml');

    expect(strtolower($phpunit))->not->toContain('sqlite')
        ->and($phpunit)->not->toContain(':memory:');

    foreach (['.env.testing', '.env.example'] as $envFile) {
        $path = Scanner::root().'/'.$envFile;

        if (is_file($path)) {
            expect((string) file_get_contents($path))->not->toMatch('/^DB_CONNECTION\s*=\s*sqlite/mi');
        }
    }

    $testFiles = Scanner::phpFiles(['tests/Unit', 'tests/Feature', 'tests/Integration']);
    expect(Scanner::violations($testFiles, ['/sqlite/i', '/:memory:/']))->toBeEmpty();
});

/** Rule 9: no secrets in the repository. */
it('does not track env files or credentials', function () {
    $tracked = Scanner::trackedFiles();

    if ($tracked === []) {
        $this->markTestSkipped('git is not available');
    }

    $trackedRelative = array_map(fn ($f) => Scanner::relative($f), $tracked);

    foreach (['.env', '.env.testing', '.env.production', '.env.backup'] as $forbidden) {
        expect($trackedRelative)->not->toContain($forbidden);
    }

    $scannable = array_values(array_filter($tracked, fn (string $f) => is_file($f)
        && preg_match('/\.(php|ts|tsx|js|json|md|xml|yml|yaml|env\.example)$/', $f) === 1
        && ! str_contains($f, 'composer.lock')
        && ! str_contains($f, 'package-lock.json')
        && ! str_ends_with($f, 'tests/Arch/ArchitectureTest.php')));

    expect(Scanner::violations($scannable, [
        '/\bck_[0-9a-f]{40}\b/',
        '/\bcs_[0-9a-f]{40}\b/',
        '/\bsk-ant-[A-Za-z0-9_-]{20,}/',
        '/\bsk-[A-Za-z0-9]{32,}\b/',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
    ], stripPhpComments: false))->toBeEmpty();
});

/** CLAUDE.md §2: every PHP file under app/ uses strict types (except framework stubs). */
it('declares strict_types in new application code', function () {
    $files = Scanner::phpFiles(['app/Modules', 'app/Support']);
    $missing = array_filter($files, fn (string $f) => ! str_contains((string) file_get_contents($f), 'declare(strict_types=1);'));

    expect($missing)->toBeEmpty();
});

/**
 * CLAUDE.md §3: never hardcode an order status string outside config/woo.php.
 * The two job-status enums are exempt: `completed` there is a run/sync outcome
 * (PRD §09 metric_runs.status, sync_jobs.status), not a WooCommerce order status.
 * Every other file — including everything under Orders — is still scanned.
 */
it('does not hardcode order status strings outside config', function () {
    $jobStatusEnums = ['app/Modules/Metrics/Enums/MetricRunStatus.php', 'app/Modules/Sync/Enums/SyncStatus.php'];
    $files = array_values(array_filter(
        Scanner::phpFiles(['app']),
        fn (string $file) => ! in_array(Scanner::relative($file), $jobStatusEnums, true),
    ));

    expect(Scanner::violations($files, ["/['\"](wc-)?(processing|completed|on-hold|cancelled|refunded)['\"]/"]))->toBeEmpty();
});
