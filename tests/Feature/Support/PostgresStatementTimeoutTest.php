<?php

declare(strict_types=1);

use App\Support\PostgresStatementTimeout;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Real PostgreSQL. `pg_sleep()` gives a deterministic, environment-independent way to prove SET
| LOCAL statement_timeout actually cancels a slow statement (SQLSTATE 57014) and that it never
| leaks onto a later query on the same connection once the transaction that set it ends.
*/

it('returns the callback result when it finishes within the timeout', function () {
    $result = PostgresStatementTimeout::run(5000, fn () => DB::selectOne('SELECT 1 AS one')->one);

    expect($result)->toBe(1);
});

it('cancels a statement that runs past the timeout, as a real Postgres QueryException', function () {
    try {
        PostgresStatementTimeout::run(50, fn () => DB::selectOne('SELECT pg_sleep(1)'));
        expect(false)->toBeTrue('Expected a QueryException, none thrown.');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('57014');
    }
});

it('never leaves the cancelled statement_timeout applied to the next query on the connection', function () {
    try {
        PostgresStatementTimeout::run(50, fn () => DB::selectOne('SELECT pg_sleep(1)'));
    } catch (QueryException) {
        // expected
    }

    $result = DB::selectOne('SELECT pg_sleep(0.2), 1 AS one')->one;

    expect($result)->toBe(1);
});
