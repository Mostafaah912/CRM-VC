<?php

declare(strict_types=1);

use App\Modules\Ai\Services\AiReadonlyRoleService;
use App\Modules\Ai\Tools\AiTool;
use App\Modules\Ai\Tools\ToolCatalog;
use Tests\Arch\Scanner;

/*
| P7-04 / GATE 4: the analyst tools reach the database ONLY through AiReadonlyDb (the `ai_readonly` role), build queries
| with the query builder and literal-only raw fragments, and nothing outside the Ai module can name them.
*/

function toolFiles(): array
{
    return Scanner::phpFiles(['app/Modules/Ai/Tools']);
}

it('lets only AiReadonlyDb touch DB::, and only for the ai_readonly connection', function () {
    $others = array_values(array_filter(toolFiles(), fn (string $f) => ! str_ends_with($f, '/AiReadonlyDb.php')));

    expect(Scanner::violations($others, ['/\bDB::/', '/ai_readonly/', '/Illuminate\\\\Support\\\\Facades\\\\DB\b/', '/->connection\(\s*[\'"]/']))->toBeEmpty();

    $own = Scanner::phpCode(Scanner::root().'/app/Modules/Ai/Tools/AiReadonlyDb.php');
    expect(substr_count($own, 'DB::connection('))->toBe(1)->and($own)->toContain("DB::connection('ai_readonly')")->and($own)->not->toMatch('/DB::(table|select|statement|raw|unprepared)/');
});

it('never uses a model, the default connection, or a raw statement helper in a tool', function () {
    expect(Scanner::violations(toolFiles(), [
        '/\\\\Models\\\\/', '/::query\(/', '/\bDB::(table|select|statement|unprepared|insert|update|delete)\b/',
        '/->(insert|update|delete|truncate|upsert|insertGetId|increment|decrement)\(/', '/\bunprepared\(/', '/->statement\(/',
    ]))->toBeEmpty();
});

it('allows raw SQL in a tool only as a pure literal: no variable, no interpolation, no helper', function () {
    $violations = [];

    foreach (toolFiles() as $file) {
        $code = Scanner::phpCode($file);

        preg_match_all('/\b(?:select|having|order|where|groupBy|from|join)Raw\((?:[^()]|\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))*\)/s', $code, $matches);

        foreach ($matches[0] as $call) {
            if (str_contains($call, '$') || preg_match('/\b(sprintf|implode|vsprintf|str_replace|json_encode)\s*\(/', $call) === 1) {
                $violations[] = Scanner::relative($file).': '.preg_replace('/\s+/', ' ', mb_substr($call, 0, 90));
            }
        }
    }

    expect($violations)->toBeEmpty();
});

it('reads only tables the hm_ai_readonly role may SELECT', function () {
    $allowed = AiReadonlyRoleService::ALLOWED_TABLES;
    $used = [];

    foreach (toolFiles() as $file) {
        preg_match_all('/->table\(\s*[\'"](\w+)[\'"]|->join\(\s*[\'"](\w+)[\'"]/', Scanner::phpCode($file), $m);
        $used = [...$used, ...array_filter([...$m[1], ...$m[2]])];
    }

    expect(array_values(array_diff(array_unique($used), $allowed)))->toBe([])->and($used)->not->toBeEmpty();
});

it('registers every tool class in ToolCatalog, each extending AiTool, and nothing else', function () {
    $classes = [];

    foreach (Scanner::phpFiles(['app/Modules/Ai/Tools/Tools']) as $file) {
        if (preg_match('/final class (\w+Tool)\b/', Scanner::phpCode($file), $m) === 1) {
            $classes[] = 'App\\Modules\\Ai\\Tools\\Tools\\'.$m[1];
        }
    }

    expect($classes)->toEqualCanonicalizing(ToolCatalog::TOOLS);

    foreach (ToolCatalog::TOOLS as $class) {
        expect(is_subclass_of($class, AiTool::class))->toBeTrue();
    }
});

it('lets no module other than Ai reference the registry or any tool', function () {
    $outside = array_values(array_filter(
        Scanner::phpFiles(['app', 'routes', 'config', 'database', 'bootstrap/app.php']),
        fn (string $f) => ! str_starts_with(Scanner::relative($f), 'app/Modules/Ai/'),
    ));

    expect(Scanner::violations($outside, ['/\bToolRegistry\b/', '/\bToolCatalog\b/', '/Ai\\\\Tools\\\\/']))->toBeEmpty();
});
