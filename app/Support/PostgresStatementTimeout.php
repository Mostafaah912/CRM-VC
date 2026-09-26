<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Runs `$callback` inside a transaction bounded by a Postgres `statement_timeout`, set via
 * `set_config('statement_timeout', ?, true)` — a normal function call, so both arguments are
 * ordinary bound parameters, never SQL text built from a value. The third argument (`is_local` =
 * `true`) makes Postgres treat it exactly like `SET LOCAL`: scoped to the current transaction only,
 * discarded automatically when it ends (commit, rollback, or an exception), so a timeout set here
 * can never leak onto a later, unrelated query on the same pooled connection.
 *
 * `SET LOCAL statement_timeout = ...` (the first version of this file) has no bound-parameter form —
 * `SET` is not a regular statement in Postgres's protocol, so the value had to be written into the
 * SQL text after casting to int. `set_config()` is a normal SQL function precisely so this file
 * never needs to do that; it is fully parameterized like any other query in the app.
 */
final class PostgresStatementTimeout
{
    public static function run(int $milliseconds, Closure $callback): mixed
    {
        $boundedMs = max(0, $milliseconds);

        return DB::transaction(function () use ($boundedMs, $callback): mixed {
            DB::selectOne('select set_config(?, ?, true)', ['statement_timeout', (string) $boundedMs]);

            return $callback();
        });
    }
}
