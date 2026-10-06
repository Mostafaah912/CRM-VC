<?php

declare(strict_types=1);

use App\Modules\Ai\Services\AiReadonlyRoleService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * P7-01 / GATE 4 (PRD §19, D7): proves the real, idempotent `hm_ai_readonly` role — not a mock —
 * actually enforces every guardrail PRD §19 lists. Runs against the real test PostgreSQL database
 * (CLAUDE.md §3/§8: no SQLite). The role's password is set directly on the `ai_readonly` connection
 * config for this test run (not read from .env.testing) so the suite needs no extra environment
 * setup beyond the admin DB credentials .env.testing already provides for `ai_admin` (which falls
 * back to the same ones as `pgsql` — see config/database.php).
 *
 * This whole file needs `ai_admin`'s credential to actually hold CREATEROLE — an environment
 * precondition this repo cannot guarantee (a fresh Postgres install's admin user often lacks it).
 * When it's missing, every test here SKIPS (not fails): this is the same precedent as
 * `TestCase::skipUnlessFortifyHas()` — an environment capability check, not a disabled assertion
 * (CLAUDE.md §9 bans disabling a test to force green; this is reporting an unmet precondition).
 */
beforeEach(function () {
    config(['database.connections.ai_readonly.password' => 'hm-ai-readonly-test-only']);

    try {
        Artisan::call('hm:ai-setup-readonly', ['--statement-timeout-ms' => 200]);
    } catch (QueryException $e) {
        $this->markTestSkipped(
            'hm:ai-setup-readonly could not run — the ai_admin connection likely lacks CREATEROLE '
            .'(ALTER ROLE <user> CREATEROLE; once, as a Postgres superuser). Underlying error: '.$e->getMessage(),
        );
    }

    config([
        'database.connections.ai_readonly.host' => config('database.connections.pgsql.host'),
        'database.connections.ai_readonly.port' => config('database.connections.pgsql.port'),
        'database.connections.ai_readonly.database' => config('database.connections.pgsql.database'),
        'database.connections.ai_readonly.username' => AiReadonlyRoleService::ROLE,
    ]);

    DB::purge('ai_readonly');
});

afterEach(function () {
    DB::purge('ai_readonly');
});

it('selects from every PRD §19-allowed table', function () {
    foreach (AiReadonlyRoleService::ALLOWED_TABLES as $table) {
        $result = DB::connection('ai_readonly')->select("select * from {$table} limit 1");

        expect($result)->toBeArray();
    }
});

it('denies every write and DDL attempt on an allowed table (GATE 4)', function () {
    // Two independent guards can reject a write here (role has no INSERT/UPDATE/DELETE/TRUNCATE
    // grant at all; default_transaction_read_only also blocks writes outright) and Postgres may
    // report either "permission denied" (42501) or "cannot execute ... in a read-only transaction"
    // (25006) depending on which one it evaluates first — this only asserts the write is rejected,
    // not which guard fired, so it stays correct either way.
    $conn = DB::connection('ai_readonly');

    expect(fn () => $conn->insert(
        'insert into metric_runs (mode, status) values (?, ?)',
        ['full', 'running'],
    ))->toThrow(QueryException::class);

    expect(fn () => $conn->update("update metric_runs set status = 'completed'"))->toThrow(QueryException::class);
    expect(fn () => $conn->delete('delete from metric_runs'))->toThrow(QueryException::class);
    expect(fn () => $conn->statement('truncate metric_runs'))->toThrow(QueryException::class);
    expect(fn () => $conn->statement('alter table metric_runs add column hacked int'))->toThrow(QueryException::class);
});

it('denies SELECT on every table carrying customer PII', function () {
    $conn = DB::connection('ai_readonly');

    foreach (['customers', 'orders', 'order_items', 'users', 'audit_logs', 'customer_addresses', 'customer_notes'] as $table) {
        expect(fn () => $conn->select("select * from {$table} limit 1"))->toThrow(QueryException::class, 'permission denied');
    }
});

it('enforces the configured statement_timeout on the role', function () {
    $raw = DB::connection('ai_readonly')->selectOne("select current_setting('statement_timeout') as v")->v;

    expect((int) preg_replace('/\D/', '', $raw))->toBe(200);
});

it('cancels a query that runs past the role\'s statement_timeout', function () {
    expect(fn () => DB::connection('ai_readonly')->select('select pg_sleep(2)'))->toThrow(QueryException::class);
});

it('runs every session read-only, independent of app config', function () {
    $raw = DB::connection('ai_readonly')->selectOne('show transaction_read_only')->transaction_read_only;

    expect($raw)->toBe('on');
});

it('is idempotent: setup can run repeatedly without error and grants never drift from ALLOWED_TABLES', function () {
    Artisan::call('hm:ai-setup-readonly');
    Artisan::call('hm:ai-setup-readonly');

    $granted = DB::connection('ai_admin')
        ->table('information_schema.role_table_grants')
        ->where('grantee', AiReadonlyRoleService::ROLE)
        ->where('privilege_type', 'SELECT')
        ->where('table_schema', 'public')
        ->pluck('table_name')
        ->sort()
        ->values()
        ->all();

    expect($granted)->toBe(collect(AiReadonlyRoleService::ALLOWED_TABLES)->sort()->values()->all());
});
