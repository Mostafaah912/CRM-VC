<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Sync\Services\FakeWooClient;
use App\Modules\Sync\Services\WooClient;
use App\Modules\Sync\Support\WooFixture;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\WooPayloads;

/*
| `hm:catalog-dry-run` (P6 decision): thin wrapper around CatalogDryRunService — prints the scan summary,
| writes nothing.
*/

it('prints the scan summary and writes nothing to the catalog', function () {
    Http::preventStrayRequests();
    $bad = WooPayloads::without(WooPayloads::items('products')[1], 'name');
    $fake = new FakeWooClient([
        new WooFixture('products', 1, [], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '2'], [WooPayloads::items('products')[0], $bad]),
    ]);
    app()->instance(WooClient::class, $fake);

    $code = Artisan::call('hm:catalog-dry-run');
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($output)->toContain('products scanned: 2, valid: 1')
        ->and($output)->toContain('name')
        ->and(Product::count())->toBe(0);
    Http::assertNothingSent();
});
