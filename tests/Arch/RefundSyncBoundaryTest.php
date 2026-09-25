<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P2-07 boundary: Woo -> (WooClient) -> RefundMapper -> Sync\RefundSyncService -> Orders' public RefundService.
| RefundService owns every refund figure and only ever RECOMPUTES it from the database (CLAUDE.md §3: never
| increment a stored aggregate). Sync never touches Orders' models or the database.
*/

function refundServiceFile(): string
{
    return Scanner::root().'/app/Modules/Orders/Services/RefundService.php';
}

it('lets RefundService reach other modules only through Core\'s AuditService, and know nothing of Woo or Sync', function () {
    expect(is_file(refundServiceFile()))->toBeTrue()
        ->and(Scanner::violations([refundServiceFile()], [
            '/\bWooClient\b/',
            '/\bHttp::/',
            '/App\\\\Modules\\\\Sync\\\\/',
            '/App\\\\Modules\\\\(Customers|Catalog)\\\\/',
            '/App\\\\Modules\\\\Core\\\\(?!Services\\\\)/',
            '/\bDB::(raw|statement|select|unprepared)\b/',
            '/\b(whereRaw|selectRaw|orderByRaw)\s*\(/',
        ]))->toBe([]);
});

it('never increments or decrements a stored refund figure: it recomputes with SUM and SETS', function () {
    $source = (string) file_get_contents(refundServiceFile());

    expect(Scanner::violations([refundServiceFile()], [
        '/->(increment|decrement|incrementEach|decrementEach)\s*\(/',
        '/(refunded_total|refunded_qty|refunded_amount)\s*(\+|-)=/',
        '/->(refunded_total|refunded_qty|refunded_amount)\s*=\s*\$[a-z]+->(refunded_total|refunded_qty|refunded_amount)\s*[+-]/i',
    ]))->toBe([])
        ->and($source)->toContain("->sum('amount')");
});

it('keeps the two rules in one place: the full-refund formula appears once, in RefundService', function () {
    $source = (string) file_get_contents(refundServiceFile());

    expect(substr_count($source, '> 0 &&'))->toBe(2);
});

it('lets the refund sync reach Orders only through its public Services — no models, database, HTTP or audit', function () {
    $file = Scanner::root().'/app/Modules/Sync/Services/RefundSyncService.php';

    expect(is_file($file))->toBeTrue()
        ->and(Scanner::violations([$file], [
            '/App\\\\Modules\\\\Orders\\\\(?!Services\\\\)/',
            '/App\\\\Modules\\\\(Catalog|Customers|Core)\\\\/',
            '/\b(DB|Http|Redis|Cache|Log)::/',
            '/\bGuzzleHttp\\\\/',
            '/->(save|update|delete|insert|create|firstOrCreate|updateOrCreate|upsert|where|query)\s*\(/',
            '/::(query|create|find|where|firstOrCreate|updateOrCreate|upsert)\s*\(/',
        ]))->toBe([]);
});

it('does not let order sync (P2-06) write refund figures — refunds remain P2-07\'s alone', function () {
    $files = [Scanner::root().'/app/Modules/Orders/Services/OrderService.php', Scanner::root().'/app/Modules/Sync/Services/OrderSyncService.php'];

    expect(Scanner::violations($files, ['/[\'"]refunded_total[\'"]\s*=>/', '/[\'"]is_fully_refunded[\'"]\s*=>/', '/->(refunded_total|is_fully_refunded)\s*=/']))->toBe([]);
});
