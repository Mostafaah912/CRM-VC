<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\IdentityConflict;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| The GATE 1 blocker migration (make_customer_id_nullable_and_add_needs_phone_review): an order with no usable phone can be
| stored, but only flagged; a phone-less conflict needs no customer; and undoing it removes what the old schema cannot hold.
*/

function phonelessColumn(string $table, string $column): object
{
    return DB::selectOne('select is_nullable, column_default, data_type from information_schema.columns where table_name = ? and column_name = ?', [$table, $column]);
}

function phonelessMigrationFile(): string
{
    return database_path('migrations/'.collect(scandir(database_path('migrations')))->first(fn (string $f) => str_ends_with($f, '_make_customer_id_nullable_and_add_needs_phone_review.php')));
}

it('makes orders.customer_id nullable and adds needs_phone_review boolean NOT NULL DEFAULT false', function () {
    $customer = phonelessColumn('orders', 'customer_id');
    $flag = phonelessColumn('orders', 'needs_phone_review');

    expect($customer->is_nullable)->toBe('YES')
        ->and($flag->data_type)->toBe('boolean')
        ->and($flag->is_nullable)->toBe('NO')
        ->and($flag->column_default)->toBe('false');
});

it('keeps the customer FK, RESTRICT: a customer that has orders can still not be hard deleted', function () {
    $order = Order::factory()->create();

    expect(fn () => DB::table('customers')->where('id', $order->customer_id)->delete())->toThrow(QueryException::class, 'orders_customer_id_foreign');
});

it('makes identity_conflicts.customer_id nullable', function () {
    expect(phonelessColumn('identity_conflicts', 'customer_id')->is_nullable)->toBe('YES');
});

it('undoes cleanly: customer-less orders (with their items) and customer-less conflicts go, everything else stays, and the old NOT NULLs return', function () {
    $customer = Customer::factory()->create();
    $kept = Order::factory()->create(['customer_id' => $customer->id]);
    $phoneless = Order::factory()->phoneless()->create();
    OrderItem::create(['order_id' => $phoneless->id, 'woo_item_id' => 1, 'sku' => 'S', 'name_snapshot' => 'N', 'qty' => 1, 'unit_price' => 1, 'line_subtotal' => 1, 'line_total' => 1, 'refunded_qty' => 0, 'refunded_amount' => 0]);
    IdentityConflict::create(['customer_id' => null, 'woo_order_id' => $phoneless->woo_order_id, 'reason' => 'no_phone', 'status' => 'pending']);
    IdentityConflict::create(['customer_id' => $customer->id, 'existing_name' => 'A', 'incoming_name' => 'B', 'woo_order_id' => $kept->woo_order_id, 'reason' => 'last_name_mismatch', 'status' => 'pending']);

    (require phonelessMigrationFile())->down();

    expect(DB::table('orders')->pluck('woo_order_id')->all())->toBe([$kept->woo_order_id])
        ->and(DB::table('order_items')->count())->toBe(0)
        ->and(DB::table('identity_conflicts')->pluck('reason')->all())->toBe(['last_name_mismatch'])
        ->and(phonelessColumn('orders', 'customer_id')->is_nullable)->toBe('NO')
        ->and(phonelessColumn('identity_conflicts', 'customer_id')->is_nullable)->toBe('NO')
        ->and(DB::selectOne("select count(*) c from information_schema.columns where table_name = 'orders' and column_name = 'needs_phone_review'")->c)->toBe(0)
        ->and(DB::selectOne("select count(*) c from pg_indexes where indexname = 'identity_conflicts_no_phone_order_unique'")->c)->toBe(0);
});
