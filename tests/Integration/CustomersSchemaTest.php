<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function insertCustomer(array $overrides = []): int
{
    return DB::table('customers')->insertGetId(array_merge([
        'phone_normalized' => '989'.fake()->unique()->numerify('#########'),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('defaults a new customer to an active prospect that needs metrics', function () {
    $row = DB::table('customers')->find(insertCustomer());

    expect($row->status)->toBe('active')
        ->and($row->lifecycle_stage)->toBe('prospect')
        ->and($row->metrics_dirty)->toBeTrue()
        ->and($row->needs_review)->toBeFalse()
        ->and($row->deleted_at)->toBeNull();
});

it('enforces one customer per phone', function () {
    insertCustomer(['phone_normalized' => '989123456789']);

    expect(fn () => insertCustomer(['phone_normalized' => '989123456789']))->toThrow(QueryException::class);
});

it('rejects an unknown status or lifecycle stage', function (string $column, string $value) {
    insertCustomer([$column => $value]);
})->with([
    ['status', 'vip'],
    ['lifecycle_stage', 'champion'],
])->throws(QueryException::class);

it('accepts every lifecycle stage in the PRD', function () {
    foreach (['prospect', 'new', 'active', 'repeat', 'loyal', 'at_risk', 'dormant', 'lost'] as $stage) {
        insertCustomer(['lifecycle_stage' => $stage]);
    }

    expect(DB::table('customers')->count())->toBe(8);
});

it('carries no denormalized aggregate counters (decision C7)', function () {
    $columns = Schema::getColumnListing('customers');

    foreach (['total_orders', 'total_revenue', 'total_refunded', 'orders_count', 'revenue', 'aov'] as $forbidden) {
        expect($columns)->not->toContain($forbidden);
    }
});

it('has the partial dirty index and the trigram name index', function () {
    $indexes = collect(DB::select("select indexname, indexdef from pg_indexes where tablename = 'customers'"))
        ->keyBy('indexname');

    expect($indexes['customers_metrics_dirty_index']->indexdef)->toContain('WHERE (metrics_dirty = true)')
        ->and($indexes['customers_display_name_trgm_index']->indexdef)->toContain('gin')->toContain('gin_trgm_ops');
});

it('enforces one identity per source id, even across customers', function () {
    $a = insertCustomer();
    $b = insertCustomer();
    $row = ['source' => 'woo_user', 'source_id' => '77'];

    DB::table('customer_identities')->insert($row + ['customer_id' => $a]);

    expect(fn () => DB::table('customer_identities')->insert($row + ['customer_id' => $b]))->toThrow(QueryException::class);
});

it('defaults identity confidence to high', function () {
    $c = insertCustomer();

    DB::table('customer_identities')->insert(['customer_id' => $c, 'source' => 'woo_guest_order', 'source_id' => '3']);

    expect(DB::table('customer_identities')->value('confidence'))->toBe('high');
});

it('rejects an invalid identity source or confidence', function (array $row) {
    DB::table('customer_identities')->insert($row + ['customer_id' => insertCustomer(), 'source_id' => '1']);
})->with([
    'source' => [['source' => 'email']],
    'confidence' => [['source' => 'woo_user', 'confidence' => 'certain']],
])->throws(QueryException::class);

it('defaults identity conflicts to pending', function () {
    DB::table('identity_conflicts')->insert(['customer_id' => insertCustomer(), 'reason' => 'name_mismatch']);

    expect(DB::table('identity_conflicts')->value('status'))->toBe('pending');
});

it('rejects an unknown identity conflict status', function () {
    DB::table('identity_conflicts')->insert(['customer_id' => insertCustomer(), 'reason' => 'x', 'status' => 'merged']);
})->throws(QueryException::class);

it('rejects an address type other than billing or shipping', function () {
    $c = insertCustomer();

    expect(fn () => DB::table('customer_addresses')->insert(['customer_id' => $c, 'type' => 'work']))->toThrow(QueryException::class);
});

it('requires every child row to reference a real customer', function (string $table, array $row) {
    expect(fn () => DB::table($table)->insert($row + ['customer_id' => 999999]))->toThrow(QueryException::class);
})->with([
    ['customer_identities', ['source' => 'woo_user', 'source_id' => '1']],
    ['identity_conflicts', ['reason' => 'x']],
    ['customer_addresses', ['type' => 'billing']],
    ['customer_notes', ['body' => 'x']],
]);

it('keeps a note when its author is deleted (notes are not recoverable from Woo)', function () {
    $c = insertCustomer();
    $userId = DB::table('users')->insertGetId([
        'name' => 'A', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_notes')->insert(['customer_id' => $c, 'user_id' => $userId, 'body' => 'called back']);

    DB::table('users')->where('id', $userId)->delete();

    $note = DB::table('customer_notes')->first();
    expect($note->body)->toBe('called back')->and($note->user_id)->toBeNull();
});

it('stores every new customer timestamp as timestamptz', function () {
    $rows = DB::select("
        select table_name, column_name from information_schema.columns
        where table_schema = 'public' and data_type = 'timestamp without time zone'
          and table_name in ('customers', 'customer_identities', 'identity_conflicts', 'customer_addresses', 'customer_notes')
    ");

    expect($rows)->toBeEmpty();
});
