<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\CustomerEventType;
use App\Modules\Customers\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| The timeline table of P3-04 (create_customer_events_table): what happened to a customer, when, with a free-form payload.
| event_type is a closed set (CHECK, mirrored by CustomerEventType); the events go with a customer that is really deleted.
*/

function eventsColumn(string $column): ?object
{
    return DB::selectOne('select data_type, is_nullable, column_default, character_maximum_length from information_schema.columns where table_name = ? and column_name = ?', ['customer_events', $column]);
}

it('has exactly the documented columns with the documented types', function () {
    $columns = collect(DB::select("select column_name from information_schema.columns where table_name = 'customer_events' order by ordinal_position"))->pluck('column_name')->all();

    expect($columns)->toBe(['id', 'customer_id', 'event_type', 'payload', 'happened_at', 'created_at'])
        ->and(eventsColumn('id')->data_type)->toBe('bigint')
        ->and(eventsColumn('customer_id')->data_type)->toBe('bigint')
        ->and(eventsColumn('customer_id')->is_nullable)->toBe('NO')
        ->and(eventsColumn('event_type')->character_maximum_length)->toBe(50)
        ->and(eventsColumn('event_type')->is_nullable)->toBe('NO')
        ->and(eventsColumn('payload')->data_type)->toBe('jsonb')
        ->and(eventsColumn('payload')->is_nullable)->toBe('YES')
        ->and(eventsColumn('happened_at')->data_type)->toBe('timestamp with time zone')
        ->and(eventsColumn('happened_at')->is_nullable)->toBe('NO')
        ->and(eventsColumn('created_at')->data_type)->toBe('timestamp with time zone')
        ->and(eventsColumn('created_at')->column_default)->toContain('now()');
});

it('indexes the one query the timeline runs: a customer\'s events by happened_at then id, both newest first', function () {
    $indexes = collect(DB::select("select indexdef from pg_indexes where tablename = 'customer_events'"))->pluck('indexdef')->implode("\n");

    expect($indexes)->toContain('(customer_id, happened_at DESC, id DESC)');
});

it('has an event_type CHECK that admits exactly the CustomerEventType values', function () {
    $check = DB::selectOne("select pg_get_constraintdef(oid) as def from pg_constraint where conname = 'customer_events_event_type_check'");

    expect($check)->not->toBeNull();

    foreach (CustomerEventType::cases() as $case) {
        expect($check->def)->toContain("'{$case->value}'");
    }

    // Exactly those values — no extra literal hiding in the constraint.
    preg_match_all("/'(\w+)'/", $check->def, $literals);

    expect($literals[1])->toHaveCount(count(CustomerEventType::cases()));
});

it('removes a customer\'s events with the customer when it is really deleted, and keeps them when it is only soft-deleted', function () {
    $customer = Customer::factory()->create();
    DB::table('customer_events')->insert(['customer_id' => $customer->id, 'event_type' => 'note_added', 'happened_at' => now()]);

    $customer->delete();
    expect(DB::table('customer_events')->where('customer_id', $customer->id)->count())->toBe(1);

    DB::table('customers')->where('id', $customer->id)->delete();
    expect(DB::table('customer_events')->where('customer_id', $customer->id)->count())->toBe(0);
});

it('refuses an event for a customer that does not exist', function () {
    $insert = fn () => DB::table('customer_events')->insert(['customer_id' => 987_654_321, 'event_type' => 'note_added', 'happened_at' => now()]);

    expect($insert)->toThrow(QueryException::class, 'customer_events_customer_id_foreign');
});

it('refuses an event_type outside the closed set', function () {
    $customer = Customer::factory()->create();
    $insert = fn () => DB::table('customer_events')->insert(['customer_id' => $customer->id, 'event_type' => 'something_else', 'happened_at' => now()]);

    // A failed statement aborts the surrounding test transaction in PostgreSQL, so this is the last statement.
    expect($insert)->toThrow(QueryException::class, 'customer_events_event_type_check');
});
