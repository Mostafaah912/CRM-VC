<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function insertCustomerRow(): int
{
    return DB::table('customers')->insertGetId([
        'phone_normalized' => '989'.fake()->unique()->numerify('#########'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function insertOrderRow(array $overrides = []): int
{
    return DB::table('orders')->insertGetId(array_merge([
        'woo_order_id' => fake()->unique()->numberBetween(1, 9_000_000),
        'customer_id' => insertCustomerRow(),
        'status' => 'processing',
        'ordered_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function insertItemRow(int $orderId, array $overrides = []): int
{
    return DB::table('order_items')->insertGetId(array_merge([
        'order_id' => $orderId,
        'name_snapshot' => 'Linen shirt',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function insertCatalogRows(): array
{
    $product = DB::table('products')->insertGetId([
        'woo_product_id' => fake()->unique()->numberBetween(1, 9_000_000), 'name' => 'Shirt', 'status' => 'publish',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $variation = DB::table('product_variations')->insertGetId([
        'product_id' => $product, 'status' => 'publish', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$product, $variation];
}

it('gives a new order the PRD defaults', function () {
    $row = DB::table('orders')->find(insertOrderRow());

    expect($row->is_realized)->toBeFalse()
        ->and($row->is_fully_refunded)->toBeFalse()
        ->and($row->total)->toBe(0)->and($row->subtotal)->toBe(0)->and($row->discount_total)->toBe(0)
        ->and($row->shipping_total)->toBe(0)->and($row->tax_total)->toBe(0)->and($row->refunded_total)->toBe(0)
        ->and($row->net_revenue)->toBe(0)
        ->and($row->coupon_codes)->toBe('[]')
        ->and($row->synced_at)->not->toBeNull()
        ->and($row->deleted_at)->toBeNull();
});

it('enforces one order per Woo order id (sync idempotency)', function () {
    insertOrderRow(['woo_order_id' => 777]);

    insertOrderRow(['woo_order_id' => 777]);
})->throws(QueryException::class);

it('stores any status string Woo sends and never rejects an order over its status', function () {
    foreach (['processing', 'completed', 'on-hold', 'refunded', 'wc-custom-shipped'] as $status) {
        insertOrderRow(['status' => $status]);
    }

    expect(DB::table('orders')->count())->toBe(5);
});

it('computes net_revenue as total - refunded_total in the database (generated column)', function () {
    $id = insertOrderRow(['total' => 1_000_000, 'refunded_total' => 250_000]);

    expect(DB::table('orders')->where('id', $id)->value('net_revenue'))->toBe(750_000);

    DB::table('orders')->where('id', $id)->update(['refunded_total' => 1_000_000]);
    expect(DB::table('orders')->where('id', $id)->value('net_revenue'))->toBe(0);

    DB::table('orders')->where('id', $id)->update(['total' => 1_200_000]);
    expect(DB::table('orders')->where('id', $id)->value('net_revenue'))->toBe(200_000);
});

it('declares net_revenue as a STORED generated column', function () {
    $column = DB::selectOne("
        select a.attgenerated, pg_get_expr(d.adbin, d.adrelid) as expr
        from pg_attribute a join pg_attrdef d on d.adrelid = a.attrelid and d.adnum = a.attnum
        where a.attrelid = 'orders'::regclass and a.attname = 'net_revenue'
    ");

    expect($column->attgenerated)->toBe('s')->and($column->expr)->toContain('total')->toContain('refunded_total');
});

it('refuses direct writes to the generated net_revenue column', function () {
    insertOrderRow(['net_revenue' => 5]);
})->throws(QueryException::class);

it('rejects a negative total or refunded_total', function (string $column) {
    insertOrderRow([$column => -1]);
})->with(['total', 'refunded_total'])->throws(QueryException::class);

it('stores every money column as bigint Toman, never numeric/float', function () {
    $columns = DB::select("
        select column_name, data_type from information_schema.columns
        where table_name = 'orders' and column_name in ('total','subtotal','discount_total','shipping_total','tax_total','refunded_total','net_revenue')
    ");

    expect($columns)->toHaveCount(7);
    foreach ($columns as $column) {
        expect($column->data_type)->toBe('bigint');
    }

    foreach (['order_items' => ['unit_price', 'line_subtotal', 'line_total', 'refunded_amount'], 'refunds' => ['amount']] as $table => $cols) {
        foreach ($cols as $col) {
            expect(DB::selectOne('select data_type from information_schema.columns where table_name = ? and column_name = ?', [$table, $col])->data_type)->toBe('bigint');
        }
    }
});

it('requires a real customer for every order', function () {
    insertOrderRow(['customer_id' => 999_999]);
})->throws(QueryException::class);

it('blocks deleting a customer who has orders (RESTRICT)', function () {
    $customerId = DB::table('orders')->where('id', insertOrderRow())->value('customer_id');

    DB::table('customers')->where('id', $customerId)->delete();
})->throws(QueryException::class, 'orders_customer_id_foreign');

it('keeps the order when it is soft deleted', function () {
    $id = insertOrderRow();

    DB::table('orders')->where('id', $id)->update(['deleted_at' => now()]);

    expect(DB::table('orders')->where('id', $id)->whereNotNull('deleted_at')->exists())->toBeTrue();
});

it('stores coupon codes as JSONB', function () {
    $id = insertOrderRow(['coupon_codes' => json_encode(['EID', 'VIP10'])]);

    expect(DB::selectOne('select coupon_codes @> ?::jsonb as has from orders where id = ?', [json_encode(['EID']), $id])->has)->toBeTrue();
});

it('has the customer/date descending index and the partial realized index', function () {
    $indexes = collect(DB::select("select indexname, indexdef from pg_indexes where tablename = 'orders'"))->keyBy('indexname');

    expect($indexes['orders_customer_ordered_at_index']->indexdef)->toContain('customer_id, ordered_at DESC')
        ->and($indexes['orders_realized_ordered_at_index']->indexdef)
        ->toContain('(is_realized, ordered_at)')->toContain('WHERE (is_realized AND (deleted_at IS NULL))');
});

it('defaults an order item to qty 1 with zero money and zero refunds', function () {
    $item = DB::table('order_items')->find(insertItemRow(insertOrderRow()));

    expect($item->qty)->toBe(1)->and($item->unit_price)->toBe(0)->and($item->line_total)->toBe(0)
        ->and($item->refunded_qty)->toBe(0)->and($item->refunded_amount)->toBe(0);
});

it('stores an unresolvable item with NULL product ids, keeping sku and name (never reject an order)', function () {
    $itemId = insertItemRow(insertOrderRow(), ['sku' => 'GONE-1', 'name_snapshot' => 'Discontinued coat', 'product_id' => null, 'variation_id' => null]);

    $item = DB::table('order_items')->find($itemId);

    expect($item->product_id)->toBeNull()->and($item->variation_id)->toBeNull()
        ->and($item->sku)->toBe('GONE-1')->and($item->name_snapshot)->toBe('Discontinued coat');
});

it('requires an item name snapshot', function () {
    insertItemRow(insertOrderRow(), ['name_snapshot' => null]);
})->throws(QueryException::class);

it('enforces one item per Woo item id within an order, but allows the same id in another order', function () {
    $a = insertOrderRow();
    $b = insertOrderRow();

    insertItemRow($a, ['woo_item_id' => 5]);
    insertItemRow($b, ['woo_item_id' => 5]);
    insertItemRow($a);
    insertItemRow($a);

    expect(fn () => insertItemRow($a, ['woo_item_id' => 5]))->toThrow(QueryException::class);
});

it('cascades item, status-history and refund deletion with the order', function () {
    $orderId = insertOrderRow();
    insertItemRow($orderId);
    DB::table('order_status_history')->insert(['order_id' => $orderId, 'to_status' => 'processing', 'changed_at' => now()]);
    DB::table('refunds')->insert(['order_id' => $orderId, 'woo_refund_id' => 1, 'amount' => 10, 'refunded_at' => now()]);

    DB::table('orders')->where('id', $orderId)->delete();

    expect(DB::table('order_items')->count())->toBe(0)
        ->and(DB::table('order_status_history')->count())->toBe(0)
        ->and(DB::table('refunds')->count())->toBe(0);
});

it('keeps an order item and nulls its product/variation link when the catalog row is deleted', function () {
    [$product, $variation] = insertCatalogRows();
    $itemId = insertItemRow(insertOrderRow(), ['product_id' => $product, 'variation_id' => $variation]);

    DB::table('product_variations')->where('id', $variation)->delete();
    expect(DB::table('order_items')->where('id', $itemId)->value('variation_id'))->toBeNull();

    DB::table('products')->where('id', $product)->delete();
    expect(DB::table('order_items')->where('id', $itemId)->exists())->toBeTrue()
        ->and(DB::table('order_items')->where('id', $itemId)->value('product_id'))->toBeNull();
});

it('requires item catalog references to point at real rows', function (string $column) {
    insertItemRow(insertOrderRow(), [$column => 999_999]);
})->with(['product_id', 'variation_id'])->throws(QueryException::class);

it('records order status history with source defaulting to sync and a nullable from_status', function () {
    $orderId = insertOrderRow();

    DB::table('order_status_history')->insert(['order_id' => $orderId, 'to_status' => 'pending', 'changed_at' => now()]);
    DB::table('order_status_history')->insert(['order_id' => $orderId, 'from_status' => 'pending', 'to_status' => 'processing', 'changed_at' => now()]);

    $rows = DB::table('order_status_history')->orderBy('id')->get();

    expect($rows[0]->from_status)->toBeNull()->and($rows[0]->source)->toBe('sync')
        ->and($rows[1]->from_status)->toBe('pending');
});

it('rejects an unknown status history source', function () {
    DB::table('order_status_history')->insert(['order_id' => insertOrderRow(), 'to_status' => 'x', 'changed_at' => now(), 'source' => 'guess']);
})->throws(QueryException::class);

it('enforces one refund per Woo refund id (sync idempotency)', function () {
    $orderId = insertOrderRow();
    $row = ['order_id' => $orderId, 'woo_refund_id' => 42, 'amount' => 100, 'refunded_at' => now()];

    DB::table('refunds')->insert($row);

    DB::table('refunds')->insert($row);
})->throws(QueryException::class);

it('defaults a refund to partial and allows several partial refunds on one order', function () {
    $orderId = insertOrderRow(['total' => 1_000_000]);

    DB::table('refunds')->insert(['order_id' => $orderId, 'woo_refund_id' => 1, 'amount' => 200_000, 'refunded_at' => now()]);
    DB::table('refunds')->insert(['order_id' => $orderId, 'woo_refund_id' => 2, 'amount' => 300_000, 'refunded_at' => now(), 'reason' => 'سایز اشتباه']);

    expect(DB::table('refunds')->pluck('is_full')->all())->toBe([false, false])
        ->and((int) DB::table('refunds')->where('order_id', $orderId)->sum('amount'))->toBe(500_000)
        ->and(DB::table('refunds')->where('woo_refund_id', 2)->value('reason'))->toBe('سایز اشتباه');
});

it('requires a real order for every child row', function (string $table, array $row) {
    DB::table($table)->insert($row + ['order_id' => 999_999]);
})->with([
    ['order_items', ['name_snapshot' => 'x']],
    ['order_status_history', ['to_status' => 'x', 'changed_at' => '2026-01-01 00:00:00+00']],
    ['refunds', ['woo_refund_id' => 1, 'amount' => 1, 'refunded_at' => '2026-01-01 00:00:00+00']],
])->throws(QueryException::class);

it('stores every order-domain timestamp as timestamptz', function () {
    $rows = DB::select("
        select table_name, column_name from information_schema.columns
        where table_schema = 'public' and data_type = 'timestamp without time zone'
          and table_name in ('orders', 'order_items', 'order_status_history', 'refunds')
    ");

    expect($rows)->toBeEmpty();
});
