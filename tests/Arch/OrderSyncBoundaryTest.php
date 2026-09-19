<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P2-06 boundary: Woo -> (WooClient) -> OrderMapper -> Sync\OrderSyncService -> Orders' public OrderService,
| which composes Customers' and Catalog's public services. Orders knows nothing of Woo or Sync; Sync never
| touches Orders' models or the database; and no refund figure is written here (P2-07 recomputes them).
*/

it('keeps the Orders module ignorant of Woo, HTTP and Sync', function () {
    expect(Scanner::violations(Scanner::phpFiles(['app/Modules/Orders']), [
        '/\bWooClient\b/',
        '/\bHttp::/',
        '/\bGuzzleHttp\\\\/',
        '/App\\\\Modules\\\\Sync\\\\/',
    ]))->toBe([]);
});

it('lets OrderService reach Customers and Catalog only through their public Services', function () {
    $file = Scanner::root().'/app/Modules/Orders/Services/OrderService.php';

    expect(is_file($file))->toBeTrue()
        ->and(Scanner::violations([$file], [
            '/App\\\\Modules\\\\(Customers|Catalog)\\\\(?!Services\\\\)/',
            '/PhoneNormalizer/',
            '/email/i',
            '/\bDB::(raw|statement|select|unprepared)\b/',
        ]))->toBe([]);
});

it('never writes the refund figures P2-07 owns', function () {
    $files = [
        Scanner::root().'/app/Modules/Orders/Services/OrderService.php',
        Scanner::root().'/app/Modules/Sync/Services/OrderSyncService.php',
    ];

    expect(array_map('is_file', $files))->each->toBeTrue();
    expect(Scanner::violations($files, [
        '/[\'"]refunded_total[\'"]\s*=>/',
        '/->refunded_total\s*=/',
        '/[\'"]is_fully_refunded[\'"]\s*=>/',
        '/->is_fully_refunded\s*=/',
        '/->(increment|decrement)\s*\(/',
        '/\bRefund\b/',
    ]))->toBe([]);
});

it('lets the order sync reach Orders only through its public Services, never its models, the database or HTTP', function () {
    $file = Scanner::root().'/app/Modules/Sync/Services/OrderSyncService.php';

    expect(Scanner::violations([$file], [
        '/App\\\\Modules\\\\Orders\\\\(?!Services\\\\)/',
        '/App\\\\Modules\\\\(Catalog|Customers)\\\\/',
        '/\b(DB|Http|Redis|Cache)::/',
        '/\bGuzzleHttp\\\\/',
        '/->(save|update|delete|insert|create|firstOrCreate|updateOrCreate|where|query)\s*\(/',
        '/(?<!orders)->upsert\s*\(/', // OrderService::upsert (PRD §07) is the one legitimate call; anything else is a model write
        '/::(query|create|find|where|firstOrCreate|updateOrCreate|upsert)\s*\(/',
        '/PhoneNormalizer|CustomerIdentityService|OrderStatusMapper/',
    ]))->toBe([]);
});

it('keeps the four-step order in one place and out of hardcoded statuses', function () {
    $service = (string) file_get_contents(Scanner::root().'/app/Modules/Orders/Services/OrderService.php');

    expect(Scanner::violations([Scanner::root().'/app/Modules/Orders/Services/OrderService.php'], ["/['\"](wc-)?(processing|completed|on-hold|cancelled|refunded|pending|failed|trash)['\"]/"]))->toBe([])
        ->and(substr_count($service, 'resolveVariationByWooId('))->toBe(1)
        ->and(substr_count($service, 'resolveVariationByProductAndSku('))->toBe(1)
        ->and(substr_count($service, 'resolveVariationBySku('))->toBe(1);
});
