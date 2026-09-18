<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P2-03 boundary: DTOs and Mappers are a pure transformation layer.
| Raw Woo response -> Mapper -> DTO. Never HTTP, Redis, DB, models, queues, or another module.
*/

function syncMappingFiles(): array
{
    return Scanner::phpFiles(['app/Modules/Sync/DTOs', 'app/Modules/Sync/Mappers']);
}

it('has DTOs and mappers to check', function () {
    expect(Scanner::phpFiles(['app/Modules/Sync/DTOs']))->not->toBeEmpty()
        ->and(Scanner::phpFiles(['app/Modules/Sync/Mappers']))->not->toBeEmpty();
});

it('keeps DTOs and mappers free of the database, HTTP, Redis, queues, logging and Eloquent', function () {
    expect(Scanner::violations(syncMappingFiles(), [
        '/\bIlluminate\\\\(Database|Http|Redis|Queue|Bus|Log|Cache|Events|Support\\\\Facades)\\\\/',
        '/\bIlluminate\\\\Support\\\\Facades\\\\/',
        '/\bGuzzleHttp\\\\/',
        '/\b(DB|Http|Redis|Queue|Bus|Cache|Log|Event|Storage)::/',
        '/\b(dispatch|dispatch_sync|event|app|resolve|cache|logger|info|now|today|config|env)\s*\(/',
        '/->(save|update|delete|insert|create|firstOrCreate|updateOrCreate|upsert|where|query)\s*\(/',
        '/::(query|create|find|where|firstOrCreate|updateOrCreate|upsert)\s*\(/',
        '/\bextends\s+Model\b/',
    ]))->toBe([]);
});

it('references nothing but Sync itself, App\\Support and plain libraries — no other module, no models', function () {
    $violations = [];

    foreach (syncMappingFiles() as $file) {
        foreach (Scanner::violations([$file], ['/App\\\\Modules\\\\(\w+)\\\\/']) as $hit) {
            preg_match('/App\\\\Modules\\\\(\w+)\\\\/', $hit, $m);

            if ($m[1] !== 'Sync') {
                $violations[] = $hit;
            }
        }
    }

    expect($violations)->toBe([])
        ->and(Scanner::violations(syncMappingFiles(), ['/App\\\\Modules\\\\\w+\\\\Models\\\\/']))->toBe([]);
});

it('does no business orchestration: mappers do not call services, identity or status logic', function () {
    expect(Scanner::violations(syncMappingFiles(), [
        '/\b\w*(Service|Action|Repository|Resolver)\b\s*(::|\()/',
        '/PhoneNormalizer/',
        '/OrderStatusMapper/',
        '/CustomerIdentity/',
    ]))->toBe([]);
});

it('exposes exactly one public operation per mapper, map()', function () {
    foreach (glob(Scanner::root().'/app/Modules/Sync/Mappers/*Mapper.php') ?: [] as $file) {
        $class = 'App\\Modules\\Sync\\Mappers\\'.basename($file, '.php');
        $public = array_map(
            fn (ReflectionMethod $m) => $m->getName(),
            array_filter((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC), fn (ReflectionMethod $m) => ! $m->isConstructor()),
        );

        expect(array_values($public))->toBe(['map'], "{$class} must only expose map()");
    }
});
