<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P2-05 boundary: Woo -> (WooClient) -> Sync mappers -> Catalog's public service -> Catalog models.
| Catalog knows nothing of Woo or Sync (PRD §07: Catalog depends on Core only); Sync never touches
| Catalog's models or the database, and never talks HTTP itself.
*/

it('keeps the Catalog module ignorant of Woo, HTTP and Sync', function () {
    expect(Scanner::violations(Scanner::phpFiles(['app/Modules/Catalog']), [
        '/\bWooClient\b/',
        '/\bHttp::/',
        '/\bGuzzleHttp\\\\/',
        '/\bIlluminate\\\\Http\\\\Client\\\\/',
        '/App\\\\Modules\\\\Sync\\\\/',
    ]))->toBe([]);
});

it('lets the catalog sync reach Catalog only through its public Services, never its models, the database or HTTP', function () {
    $file = Scanner::root().'/app/Modules/Sync/Services/CatalogSyncService.php';

    expect(is_file($file))->toBeTrue()
        ->and(Scanner::violations([$file], [
            '/App\\\\Modules\\\\Catalog\\\\(?!Services\\\\)/',
            '/App\\\\Modules\\\\(?!Sync\\\\|Catalog\\\\Services\\\\)\w+\\\\/',
            '/\b(DB|Http|Redis|Cache)::/',
            '/\bGuzzleHttp\\\\/',
            '/->(save|update|delete|insert|create|firstOrCreate|updateOrCreate|upsert|where|query)\s*\(/',
            '/::(query|create|find|where|firstOrCreate|updateOrCreate|upsert)\s*\(/',
        ]))->toBe([]);
});

it('gives the Catalog service typed inputs, not raw Woo JSON', function () {
    $service = new ReflectionClass('App\\Modules\\Catalog\\Services\\CatalogService');

    expect((string) $service->getMethod('upsertProduct')->getParameters()[0]->getType())->toBe('App\\Modules\\Catalog\\Services\\ProductInput');

    foreach (['CategoryInput', 'ProductInput', 'VariationInput'] as $input) {
        $reflection = new ReflectionClass("App\\Modules\\Catalog\\Services\\{$input}");
        expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();
    }
});
