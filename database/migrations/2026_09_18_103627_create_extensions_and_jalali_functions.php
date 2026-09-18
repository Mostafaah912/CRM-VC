<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PRD §09 migration 001: extensions + Jalali PL/pgSQL functions.
 *
 * to_jalali/to_jalali_month/jalali_month_diff use the same 33-year-cycle
 * algorithm as App\Support\JalaliDate (P0-03) so the PHP side and the SQL
 * side of the Metrics engine (P4+) always agree on the same dates.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION to_jalali(ts timestamptz)
            RETURNS text
            LANGUAGE plpgsql
            STABLE
            AS $func$
            DECLARE
                local_ts timestamp;
                gy int;
                gm int;
                gd int;
                gy2 int;
                gm2 int;
                gd2 int;
                g_day_no int;
                j_day_no int;
                j_np int;
                jy int;
                jm int;
                jd int;
                i int;
                g_days_in_month int[] := ARRAY[31,28,31,30,31,30,31,31,30,31,30,31];
                j_days_in_month int[] := ARRAY[31,31,31,31,31,31,30,30,30,30,30,29];
                is_leap boolean;
            BEGIN
                IF ts IS NULL THEN
                    RETURN NULL;
                END IF;

                local_ts := ts AT TIME ZONE 'Asia/Tehran';
                gy := EXTRACT(YEAR FROM local_ts)::int;
                gm := EXTRACT(MONTH FROM local_ts)::int;
                gd := EXTRACT(DAY FROM local_ts)::int;

                gy2 := gy - 1600;
                gm2 := gm - 1;
                gd2 := gd - 1;

                g_day_no := 365 * gy2 + (gy2 + 3) / 4 - (gy2 + 99) / 100 + (gy2 + 399) / 400;

                FOR i IN 0..(gm2 - 1) LOOP
                    g_day_no := g_day_no + g_days_in_month[i + 1];
                END LOOP;

                is_leap := (gy % 4 = 0 AND gy % 100 <> 0) OR (gy % 400 = 0);
                IF gm2 > 1 AND is_leap THEN
                    g_day_no := g_day_no + 1;
                END IF;

                g_day_no := g_day_no + gd2;

                j_day_no := g_day_no - 79;

                j_np := j_day_no / 12053;
                j_day_no := j_day_no % 12053;

                jy := 979 + 33 * j_np + 4 * (j_day_no / 1461);
                j_day_no := j_day_no % 1461;

                IF j_day_no >= 366 THEN
                    jy := jy + (j_day_no - 1) / 365;
                    j_day_no := (j_day_no - 1) % 365;
                END IF;

                i := 0;
                WHILE i < 11 AND j_day_no >= j_days_in_month[i + 1] LOOP
                    j_day_no := j_day_no - j_days_in_month[i + 1];
                    i := i + 1;
                END LOOP;

                jm := i + 1;
                jd := j_day_no + 1;

                RETURN lpad(jy::text, 4, '0') || '-' || lpad(jm::text, 2, '0') || '-' || lpad(jd::text, 2, '0');
            END;
            $func$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION to_jalali_month(ts timestamptz)
            RETURNS text
            LANGUAGE sql
            STABLE
            AS $func$
                SELECT substring(to_jalali(ts) FROM 1 FOR 7);
            $func$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION jalali_month_diff(month_a text, month_b text)
            RETURNS int
            LANGUAGE sql
            IMMUTABLE
            AS $func$
                SELECT
                    (split_part(month_b, '-', 1)::int * 12 + split_part(month_b, '-', 2)::int)
                    -
                    (split_part(month_a, '-', 1)::int * 12 + split_part(month_a, '-', 2)::int)
            $func$
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS jalali_month_diff(text, text)');
        DB::statement('DROP FUNCTION IF EXISTS to_jalali_month(timestamptz)');
        DB::statement('DROP FUNCTION IF EXISTS to_jalali(timestamptz)');
        DB::statement('DROP EXTENSION IF EXISTS pg_trgm');
    }
};
