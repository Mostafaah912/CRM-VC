<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Runs `$callback` inside a transaction bounded by a Postgres `statement_timeout`. `SET LOCAL`
 * only applies for the current transaction and is discarded automatically when it ends (commit,
 * rollback, or an exception), so a timeout set here can never leak onto a later, unrelated query
 * on the same pooled connection.
 *
 * Lives here, not inside `app/Modules/Segments`, because that module bans `DB::statement`/`DB::raw`
 * outright (CLAUDE.md §3, enforced by an arch test) as its SQL-injection defense — this helper is the
 * one place a numeric literal is written into SQL text, and PostgreSQL's `SET` command has no bound-
 * parameter form to avoid that (`SET LOCAL statement_timeout = $1` is a syntax error). `$milliseconds`
 * is always an app-controlled integer (a class constant or a config default), never request input.
 */
final class PostgresStatementTimeout
{
    public static function run(int $milliseconds, Closure $callback): mixed
    {
        $boundedMs = max(0, $milliseconds);

        return DB::transaction(function () use ($boundedMs, $callback): mixed {
            DB::statement('SET LOCAL statement_timeout = '.$boundedMs);

            return $callback();
        });
    }
}
