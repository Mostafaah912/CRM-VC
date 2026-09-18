<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `users` table: adds the columns the CRM needs beyond Laravel's
 * default auth scaffold (is_active, last_login_at) and tightens name/email
 * to the lengths specified in the schema. email_verified_at,
 * two_factor_recovery_codes stay — Fortify's email verification and 2FA
 * recovery flow (P0-06) depend on them; PRD's table listing isn't exhaustive
 * of framework-required columns.
 *
 * Also converts every real timestamp column on `users` from Postgres'
 * `timestamp without time zone` (Laravel's default on this driver) to
 * `timestamptz`, per CLAUDE.md §2 ("Timestamps are timestamptz stored in
 * UTC"). The values were always written in UTC by Laravel; reinterpreting
 * the naive column as UTC is a lossless, purely-typed conversion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('password');
            $table->timestampTz('last_login_at')->nullable()->after('remember_token');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('name', 120)->change();
            $table->string('email', 160)->change();
        });

        foreach (['email_verified_at', 'two_factor_confirmed_at', 'created_at', 'updated_at'] as $column) {
            DB::statement("ALTER TABLE users ALTER COLUMN {$column} TYPE timestamptz USING {$column} AT TIME ZONE 'UTC'");
        }
    }

    public function down(): void
    {
        foreach (['email_verified_at', 'two_factor_confirmed_at', 'created_at', 'updated_at'] as $column) {
            DB::statement("ALTER TABLE users ALTER COLUMN {$column} TYPE timestamp(0) without time zone USING {$column} AT TIME ZONE 'UTC'");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'last_login_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('name', 255)->change();
            $table->string('email', 255)->change();
        });
    }
};
