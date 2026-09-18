<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;

/** Reads the real PostgreSQL catalog so schema tests assert what the database actually enforces. */
final class SchemaProbe
{
    /**
     * Compare a table against a column spec. Spec value: [type, nullable, defaultContains|null].
     * Types: bigint int smallint tstz date jsonb text bool varchar(N) numeric(P,S).
     *
     * @param  array<string, array{0: string, 1: bool, 2: string|null}>  $spec
     * @return list<string> human-readable mismatches (empty = schema matches)
     */
    public static function mismatches(string $table, array $spec): array
    {
        $problems = [];

        foreach ($spec as $column => [$type, $nullable, $default]) {
            $row = DB::selectOne(
                'select data_type, is_nullable, column_default, character_maximum_length, numeric_precision, numeric_scale
                 from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
                ['public', $table, $column],
            );

            if ($row === null) {
                $problems[] = "{$table}.{$column} is missing";

                continue;
            }

            $actualType = self::describe($row);
            if ($actualType !== $type) {
                $problems[] = "{$table}.{$column} type is {$actualType}, expected {$type}";
            }

            if (($row->is_nullable === 'YES') !== $nullable) {
                $problems[] = "{$table}.{$column} nullable is ".($nullable ? 'NO' : 'YES').', expected '.($nullable ? 'YES' : 'NO');
            }

            if ($default !== null && ! str_contains(strtolower((string) $row->column_default), strtolower($default))) {
                $problems[] = "{$table}.{$column} default is [{$row->column_default}], expected to contain [{$default}]";
            }
        }

        $extra = array_diff(self::columns($table), array_keys($spec));
        foreach ($extra as $column) {
            $problems[] = "{$table}.{$column} exists but is not in the PRD schema";
        }

        return $problems;
    }

    /** @return list<string> */
    public static function columns(string $table): array
    {
        return array_map(
            fn ($r) => $r->column_name,
            DB::select('select column_name from information_schema.columns where table_schema = ? and table_name = ?', ['public', $table]),
        );
    }

    public static function exists(string $table): bool
    {
        return DB::selectOne('select to_regclass(?) as t', ["public.{$table}"])->t !== null;
    }

    /** @return list<string> */
    public static function primaryKey(string $table): array
    {
        return array_map(fn ($r) => $r->attname, DB::select(
            'select a.attname from pg_index i join pg_attribute a on a.attrelid = i.indrelid and a.attnum = any(i.indkey)
             where i.indrelid = ?::regclass and i.indisprimary order by array_position(i.indkey::int2[], a.attnum)',
            [$table],
        ));
    }

    /** @return array{ref_table: string, delete_rule: string}|null */
    public static function foreignKey(string $table, string $column): ?array
    {
        $row = DB::selectOne(
            "select rc.delete_rule, ccu.table_name as ref_table
             from information_schema.key_column_usage kcu
             join information_schema.referential_constraints rc on rc.constraint_name = kcu.constraint_name and rc.constraint_schema = kcu.constraint_schema
             join information_schema.constraint_column_usage ccu on ccu.constraint_name = rc.constraint_name and ccu.constraint_schema = rc.constraint_schema
             where kcu.table_schema = 'public' and kcu.table_name = ? and kcu.column_name = ?",
            [$table, $column],
        );

        return $row === null ? null : ['ref_table' => $row->ref_table, 'delete_rule' => $row->delete_rule];
    }

    /** @return list<string> */
    public static function checks(string $table): array
    {
        return array_map(fn ($r) => $r->def, DB::select(
            "select pg_get_constraintdef(oid) as def from pg_constraint where conrelid = ?::regclass and contype = 'c'",
            [$table],
        ));
    }

    /** @return array<string, string> index name => definition */
    public static function indexes(string $table): array
    {
        $out = [];
        foreach (DB::select("select indexname, indexdef from pg_indexes where schemaname = 'public' and tablename = ?", [$table]) as $row) {
            $out[$row->indexname] = $row->indexdef;
        }

        return $out;
    }

    public static function hasUniqueOn(string $table, string ...$columns): bool
    {
        $needle = '('.implode(', ', $columns).')';

        foreach (self::indexes($table) as $definition) {
            if (str_contains($definition, 'UNIQUE') && str_contains($definition, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function hasIndexOn(string $table, string $columnsSql): bool
    {
        foreach (self::indexes($table) as $definition) {
            if (str_contains($definition, "({$columnsSql})")) {
                return true;
            }
        }

        return false;
    }

    private static function describe(object $row): string
    {
        return match ($row->data_type) {
            'bigint' => 'bigint',
            'integer' => 'int',
            'smallint' => 'smallint',
            'timestamp with time zone' => 'tstz',
            'timestamp without time zone' => 'timestamp-naive',
            'date' => 'date',
            'jsonb' => 'jsonb',
            'text' => 'text',
            'boolean' => 'bool',
            'character varying' => "varchar({$row->character_maximum_length})",
            'numeric' => "numeric({$row->numeric_precision},{$row->numeric_scale})",
            default => $row->data_type,
        };
    }
}
