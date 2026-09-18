<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/** Hash of every demo-seeded table, row order and ids included — equal hashes mean identical data. */
final class DemoSnapshot
{
    /** Parents first; the pivot has no id column so it is ordered by both keys. */
    private const TABLES = [
        'product_categories' => 'id', 'products' => 'id', 'product_category_product' => 'product_id, category_id',
        'product_variations' => 'id', 'customers' => 'id', 'customer_identities' => 'id', 'identity_conflicts' => 'id',
        'customer_addresses' => 'id', 'customer_notes' => 'id', 'orders' => 'id', 'order_items' => 'id',
        'order_status_history' => 'id', 'refunds' => 'id',
    ];

    /** @return array<string, string> table => md5 of its rows */
    public static function take(): array
    {
        $hashes = [];

        foreach (self::TABLES as $table => $order) {
            $hashes[$table] = (string) DB::selectOne(
                "select coalesce(md5(string_agg(row_to_json(t)::text, '|' order by {$order})), 'empty') as h from {$table} t",
            )->h;
        }

        return $hashes;
    }

    public static function fingerprint(): string
    {
        return md5(implode('|', self::take()));
    }

    public static function truncateDemoTables(): void
    {
        DB::statement('TRUNCATE '.implode(', ', array_reverse(array_keys(self::TABLES))).' RESTART IDENTITY CASCADE');
    }
}
