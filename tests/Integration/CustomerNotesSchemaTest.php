<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| P3-05 (harden_customer_notes_table): customer_notes was created in P1-01 and never written; the P3-05 migration makes the author
| mandatory and undeletable (NOT NULL + RESTRICT), the timestamp NOT NULL with a default, the body 1..2000 characters, and replaces the
| (customer_id) index with the list's own (customer_id, created_at DESC, id DESC). Notes go with a customer that is really deleted.
*/

function notesColumn(string $column): ?object
{
    return DB::selectOne('select data_type, is_nullable, column_default from information_schema.columns where table_name = ? and column_name = ?', ['customer_notes', $column]);
}

function notesFk(string $name): string
{
    return (string) DB::selectOne('select pg_get_constraintdef(oid) as def from pg_constraint where conname = ?', [$name])?->def;
}

function noteRow(array $overrides = []): array
{
    return [
        'customer_id' => Customer::factory()->create()->id,
        'user_id' => User::factory()->create()->id,
        'body' => 'called back',
        ...$overrides,
    ];
}

it('has the documented columns: an author that cannot be null, and a creation time with a default', function () {
    $columns = collect(DB::select("select column_name from information_schema.columns where table_name = 'customer_notes' order by ordinal_position"))->pluck('column_name')->all();

    expect($columns)->toBe(['id', 'customer_id', 'user_id', 'body', 'created_at', 'updated_at'])
        ->and(notesColumn('user_id')->is_nullable)->toBe('NO')
        ->and(notesColumn('body')->data_type)->toBe('text')
        ->and(notesColumn('body')->is_nullable)->toBe('NO')
        ->and(notesColumn('created_at')->data_type)->toBe('timestamp with time zone')
        ->and(notesColumn('created_at')->is_nullable)->toBe('NO')
        ->and(notesColumn('created_at')->column_default)->toContain('now()');
});

it('indexes the notes list: a customer\'s notes by created_at then id, both newest first — and the redundant single-column index is gone', function () {
    $indexes = collect(DB::select("select indexname, indexdef from pg_indexes where tablename = 'customer_notes'"));

    expect($indexes->pluck('indexdef')->implode("\n"))->toContain('(customer_id, created_at DESC, id DESC)')
        ->and($indexes->pluck('indexname')->all())->not->toContain('customer_notes_customer_id_index');
});

it('cascades from a customer and restricts the author', function () {
    expect(notesFk('customer_notes_customer_id_foreign'))->toContain('ON DELETE CASCADE')
        ->and(notesFk('customer_notes_user_id_foreign'))->toContain('ON DELETE RESTRICT');
});

it('removes a customer\'s notes with the customer when it is really deleted, and keeps them when it is only soft-deleted', function () {
    $row = noteRow();
    DB::table('customer_notes')->insert($row);
    $customer = Customer::query()->findOrFail($row['customer_id']);

    $customer->delete();
    expect(DB::table('customer_notes')->where('customer_id', $row['customer_id'])->count())->toBe(1);

    DB::table('customers')->where('id', $row['customer_id'])->delete();
    expect(DB::table('customer_notes')->where('customer_id', $row['customer_id'])->count())->toBe(0);
});

it('stamps created_at itself when a writer gives none', function () {
    DB::table('customer_notes')->insert(noteRow());

    expect(DB::table('customer_notes')->value('created_at'))->not->toBeNull();
});

it('accepts a body of 1 and of 2000 characters — counted as characters, so Persian text is not penalised', function () {
    DB::table('customer_notes')->insert(noteRow(['body' => 'x']));
    DB::table('customer_notes')->insert(noteRow(['body' => str_repeat('ی', 2000)])); // 4000 bytes

    expect(DB::table('customer_notes')->count())->toBe(2);
});

it('refuses an empty or blank body', function (string $body) {
    $insert = fn () => DB::table('customer_notes')->insert(noteRow(['body' => $body]));

    expect($insert)->toThrow(QueryException::class, 'customer_notes_body_check');
})->with(['empty' => [''], 'spaces' => ['   '], 'newlines and tabs' => ["\n\t \n"]]);

it('refuses a body of 2001 characters', function () {
    $insert = fn () => DB::table('customer_notes')->insert(noteRow(['body' => str_repeat('ی', 2001)]));

    expect($insert)->toThrow(QueryException::class, 'customer_notes_body_check');
});

it('refuses a note without an author', function () {
    $insert = fn () => DB::table('customer_notes')->insert(noteRow(['user_id' => null]));

    expect($insert)->toThrow(QueryException::class, 'user_id');
});
