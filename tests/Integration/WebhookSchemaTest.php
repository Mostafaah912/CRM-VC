<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Integration\SchemaProbe;

/*
| P2-09 `woo_webhook_deliveries`: one row per accepted delivery, keyed by Woo's delivery id (varchar — real Woo sends
| a 32-character hash, not an integer). The UNIQUE constraint is the deduplication, so it holds even when two identical
| deliveries race.
*/

function deliveryRow(array $overrides = []): array
{
    return array_merge(['topic' => 'order.updated', 'woo_delivery_id' => 'd-1'], $overrides);
}

it('has woo_webhook_deliveries exactly as P2-09 defines it', function () {
    expect(SchemaProbe::mismatches('woo_webhook_deliveries', [
        'id' => ['bigint', false, null],
        'topic' => ['varchar(30)', false, null],
        'woo_delivery_id' => ['varchar(64)', false, null],
        'received_at' => ['tstz', false, 'current_timestamp'],
    ]))->toBeEmpty()
        ->and(SchemaProbe::primaryKey('woo_webhook_deliveries'))->toBe(['id'])
        ->and(SchemaProbe::hasUniqueOn('woo_webhook_deliveries', 'woo_delivery_id'))->toBeTrue();
});

it('stamps received_at itself', function () {
    DB::table('woo_webhook_deliveries')->insert(deliveryRow());

    expect(DB::table('woo_webhook_deliveries')->first()->received_at)->not->toBeNull();
});

it('accepts each routed topic', function (string $topic) {
    DB::table('woo_webhook_deliveries')->insert(deliveryRow(['topic' => $topic]));

    expect(DB::table('woo_webhook_deliveries')->count())->toBe(1);
})->with(['order.created', 'order.updated', 'order.deleted']);

it('rejects a topic that is not routed', function (string $topic) {
    DB::table('woo_webhook_deliveries')->insert(deliveryRow(['topic' => $topic]));
})->with(['product.created', 'refund.created', 'woocommerce.order.created', ''])->throws(QueryException::class);

it('stores a 64-character delivery id and rejects a longer one', function () {
    DB::table('woo_webhook_deliveries')->insert(deliveryRow(['woo_delivery_id' => str_repeat('a', 64)]));

    expect(DB::table('woo_webhook_deliveries')->count())->toBe(1);

    DB::table('woo_webhook_deliveries')->insert(deliveryRow(['woo_delivery_id' => str_repeat('a', 65)]));
})->throws(QueryException::class);

it('keeps one row per delivery id, whatever the topic', function () {
    DB::table('woo_webhook_deliveries')->insert(deliveryRow(['topic' => 'order.created']));
    DB::table('woo_webhook_deliveries')->insert(deliveryRow(['topic' => 'order.deleted']));
})->throws(QueryException::class);

it('has a working down()', function () {
    $migration = require database_path('migrations/'.collect(scandir(database_path('migrations')))->first(fn (string $f) => str_ends_with($f, '_create_woo_webhook_deliveries_table.php')));

    $migration->down();

    expect(Schema::hasTable('woo_webhook_deliveries'))->toBeFalse();
});
