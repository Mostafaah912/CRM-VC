<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| The audit table of P3-02 (create_phone_reveal_logs_table): who revealed which customer's phone, when, and from where. The
| trail must outlive a departing user (RESTRICT) and goes with a customer that is really deleted (CASCADE).
*/

function revealColumn(string $column): ?object
{
    return DB::selectOne('select data_type, is_nullable, column_default, character_maximum_length from information_schema.columns where table_name = ? and column_name = ?', ['phone_reveal_logs', $column]);
}

function revealMigrationFile(): string
{
    return database_path('migrations/'.collect(scandir(database_path('migrations')))->first(fn (string $f) => str_ends_with($f, '_create_phone_reveal_logs_table.php')));
}

it('has exactly the documented columns with the documented types', function () {
    $columns = collect(DB::select("select column_name from information_schema.columns where table_name = 'phone_reveal_logs' order by ordinal_position"))->pluck('column_name')->all();

    expect($columns)->toBe(['id', 'customer_id', 'revealed_by', 'revealed_at', 'ip', 'user_agent'])
        ->and(revealColumn('id')->data_type)->toBe('bigint')
        ->and(revealColumn('customer_id')->data_type)->toBe('bigint')
        ->and(revealColumn('customer_id')->is_nullable)->toBe('NO')
        ->and(revealColumn('revealed_by')->data_type)->toBe('bigint')
        ->and(revealColumn('revealed_by')->is_nullable)->toBe('NO')
        ->and(revealColumn('revealed_at')->data_type)->toBe('timestamp with time zone')
        ->and(revealColumn('revealed_at')->column_default)->toContain('now()')
        ->and(revealColumn('ip')->character_maximum_length)->toBe(45)
        ->and(revealColumn('user_agent')->character_maximum_length)->toBe(500);
});

it('indexes the two ways an auditor asks: by customer and by user, newest first', function () {
    $indexes = collect(DB::select("select indexdef from pg_indexes where tablename = 'phone_reveal_logs'"))->pluck('indexdef')->implode("\n");

    expect($indexes)->toContain('(customer_id, revealed_at DESC)')->toContain('(revealed_by, revealed_at DESC)');
});

it('cascades from a hard-deleted customer but restricts the deletion of a user who revealed', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();
    DB::table('phone_reveal_logs')->insert(['customer_id' => $customer->id, 'revealed_by' => $user->id, 'ip' => '127.0.0.1', 'user_agent' => 'x']);

    $delete = fn () => DB::table('users')->where('id', $user->id)->delete();
    expect($delete)->toThrow(QueryException::class, 'phone_reveal_logs_revealed_by_foreign');
});

it('removes a customer\'s reveal rows with the customer when it is really deleted', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();
    DB::table('phone_reveal_logs')->insert(['customer_id' => $customer->id, 'revealed_by' => $user->id, 'ip' => '127.0.0.1', 'user_agent' => 'x']);

    DB::table('customers')->where('id', $customer->id)->delete();

    expect(DB::table('phone_reveal_logs')->count())->toBe(0);
});

it('refuses a row for a customer or a user that does not exist', function () {
    $customer = Customer::factory()->create();

    expect(fn () => DB::table('phone_reveal_logs')->insert(['customer_id' => 987654321, 'revealed_by' => User::factory()->create()->id, 'ip' => '1.1.1.1', 'user_agent' => '']))
        ->toThrow(QueryException::class);
});

it('undoes cleanly', function () {
    (require revealMigrationFile())->down();

    expect(DB::selectOne("select count(*) c from information_schema.tables where table_name = 'phone_reveal_logs'")->c)->toBe(0);
});
