<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P7-01 / GATE 4 (PRD §19, D7): creates and maintains `hm_ai_readonly`, the one PostgreSQL role the
 * AI Analyst module is ever allowed to connect as (`config('database.connections.ai_readonly')`).
 * Idempotent: REVOKE ALL then GRANT only the current ALLOWED_TABLES every run, so re-running after
 * ALLOWED_TABLES changes — or after DB_AI_READONLY_PASSWORD rotates in .env — converges the role to
 * exactly the intended state rather than accumulating stale grants.
 *
 * CREATE ROLE / GRANT / REVOKE / ALTER ROLE have no Eloquent or query-builder form — Schema:: has
 * nothing for cluster-level roles. This file is a named, documented exception to CLAUDE.md §3's
 * raw-SQL ban (tests/Arch/ArchitectureTest.php, Rule 7 allow-list), exactly like the existing P3-07
 * Catalog exception. It is only ever invoked from `hm:ai-setup-readonly` (an explicit artisan
 * command) — never at request time, never from a migration (roles are cluster-level objects, not
 * part of this app's schema, and the password must come from env, not a committed migration file).
 *
 * Runs via the `ai_admin` connection (`config('database.connections.ai_admin')`), not the app's
 * everyday `pgsql` connection: CREATEROLE is a meaningful privilege escalation (it lets its holder
 * create/alter arbitrary roles cluster-wide), and the app's regular runtime credential — used for
 * every Woo-sync write, every request — has no reason to carry it permanently. `ai_admin` falls
 * back to the same `DB_*` values as `pgsql` when `DB_AI_ADMIN_*` is unset, so this still works
 * wherever only one admin credential exists yet; setting `DB_AI_ADMIN_*` to a narrower credential
 * (CREATEROLE only, nothing else) is a drop-in upgrade with no code change.
 */
final class AiReadonlyRoleService
{
    public const string ROLE = 'hm_ai_readonly';

    /** PRD §19's own guardrail list — the only tables the AI role may ever SELECT from. No PII column exists on any of them (verified P7-01: no name/phone/email/address anywhere in these 9 tables). */
    public const array ALLOWED_TABLES = [
        'daily_metrics', 'cohort_snapshots', 'product_affinities', 'customer_metrics',
        'segments', 'segment_members', 'products', 'product_categories', 'metric_runs',
    ];

    public function setup(int $statementTimeoutMs = 5000): void
    {
        $password = (string) config('database.connections.ai_readonly.password');

        if ($password === '') {
            throw new RuntimeException('database.connections.ai_readonly.password is empty — set DB_AI_READONLY_PASSWORD before running hm:ai-setup-readonly.');
        }

        $admin = DB::connection('ai_admin');
        $quotedPassword = $admin->getPdo()->quote($password);
        $tableList = implode(', ', self::ALLOWED_TABLES);

        if ($this->roleExists($admin)) {
            $admin->statement('ALTER ROLE '.self::ROLE." LOGIN PASSWORD {$quotedPassword}");
        } else {
            $admin->statement('CREATE ROLE '.self::ROLE." LOGIN PASSWORD {$quotedPassword}");
        }

        // Reset to exactly ALLOWED_TABLES every run — never an incremental GRANT, so a table removed
        // from the list above (or never added) cannot stay reachable from a previous run.
        $admin->statement('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM '.self::ROLE);
        $admin->statement('GRANT USAGE ON SCHEMA public TO '.self::ROLE);
        $admin->statement('GRANT SELECT ON '.$tableList.' TO '.self::ROLE);

        // Role-level restrictions (CLAUDE.md §9 "do not give AI a write tool" / PRD §19 step 5):
        // enforced server-side on every session that connects as this role, regardless of which
        // app connection config is used to reach it.
        $admin->statement('ALTER ROLE '.self::ROLE.' SET default_transaction_read_only = on');
        $admin->statement('ALTER ROLE '.self::ROLE." SET statement_timeout = '{$statementTimeoutMs}'");
        $admin->statement('ALTER ROLE '.self::ROLE." SET search_path = 'public'");
    }

    private function roleExists(Connection $admin): bool
    {
        return $admin->selectOne('SELECT 1 FROM pg_catalog.pg_roles WHERE rolname = ?', [self::ROLE]) !== null;
    }
}
