<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Ai\Services\AiReadonlyRoleService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Real-PostgreSQL fixture for the AI tools. The tools read through the `ai_readonly` role, a SEPARATE session, so the
 * rows must be COMMITTED — RefreshDatabase's open transaction on the default connection would hide them. Fixture rows
 * are therefore written (and removed again) through `ai_admin`, which autocommits. Everything here lives in the test
 * database only. Fake PII (names, phones in both digit systems, emails) is planted in `customers` so tests can prove no
 * tool output contains it.
 */
final class AiToolsFixture
{
    public const TABLES = 'customers, products, product_categories, segments, daily_metrics, cohort_snapshots, product_affinities, metric_runs';

    /** Strings that must never appear in any tool output. */
    public const PII = ['علی', 'رضایی', 'Rezaei', 'ali.rezaei@example.com', '09121234567', '۰۹۱۲۱۲۳۴۵۶۷', '+989121234567', 'خیابان ولیعصر', 'Tehran Vali-asr'];

    /** @return string|null why the environment cannot run these tests (→ skip), null when ready */
    public static function boot(): ?string
    {
        if ((string) config('database.connections.ai_readonly.password') === '') {
            return 'DB_AI_READONLY_PASSWORD is not set in .env.testing.';
        }

        try {
            Artisan::call('hm:ai-setup-readonly');
            self::admin()->statement('select 1');
            self::wipe();
        } catch (\Throwable) {
            return 'The ai_admin connection cannot create the role / write the test database (CREATEROLE + superuser needed).';
        }

        config([
            'database.connections.ai_readonly.host' => config('database.connections.pgsql.host'),
            'database.connections.ai_readonly.port' => config('database.connections.pgsql.port'),
            'database.connections.ai_readonly.database' => config('database.connections.pgsql.database'),
            'database.connections.ai_readonly.username' => AiReadonlyRoleService::ROLE,
        ]);
        DB::purge('ai_readonly');

        return null;
    }

    public static function admin(): Connection
    {
        /** @var Connection $c */
        $c = DB::connection('ai_admin');

        if (config('database.connections.ai_admin.database') !== config('database.connections.pgsql.database')) {
            throw new RuntimeException('ai_admin must point at the TEST database.');
        }

        return $c;
    }

    public static function wipe(): void
    {
        self::admin()->statement('TRUNCATE '.self::TABLES.' RESTART IDENTITY CASCADE');
    }

    public static function teardown(): void
    {
        DB::purge('ai_readonly');

        try {
            self::wipe();
        } catch (\Throwable) {
        }
    }

    /**
     * 12 customers: 6 champions (4 orders), 4 loyal (2), 2 at_risk (1). churn: 7 low, 3 medium, 2 high.
     *
     * @return array{customers: list<int>}
     */
    public static function seed(): array
    {
        $db = self::admin();
        $ids = [];

        foreach (range(1, 12) as $i) {
            $ids[] = (int) $db->table('customers')->insertGetId([
                'phone_normalized' => '0912'.str_pad((string) (1234560 + $i), 7, '0', STR_PAD_LEFT),
                'phone_raw_last' => '۰۹۱۲۱۲۳۴۵۶۷',
                'first_name' => 'علی', 'last_name' => 'رضایی', 'display_name' => 'علی رضایی Rezaei',
                'email' => 'ali.rezaei@example.com', 'province' => 'Tehran Vali-asr', 'city' => 'خیابان ولیعصر',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ($ids as $n => $id) {
            [$segment, $orders, $monetary, $recency, $level, $score] = match (true) {
                $n < 6 => ['champion', 4, 1_000_000, 10, $n < 5 ? 'low' : 'medium', 10],
                $n < 10 => ['loyal', 2, 400_000, 40, $n < 8 ? 'low' : 'medium', 30],
                default => ['at_risk', 1, 100_000, 120, 'high', 80],
            };
            $db->table('customer_metrics')->insert([
                'customer_id' => $id, 'total_orders' => $orders, 'total_revenue' => $monetary, 'aov' => intdiv($monetary, $orders),
                'recency_days' => $recency, 'frequency' => $orders, 'monetary' => $monetary, 'rfm_segment' => $segment,
                'clv_historical' => $monetary, 'clv_estimated' => $orders >= 2 ? $monetary * 2 : null, 'clv_confidence' => $orders >= 2 ? 'high' : 'low',
                'churn_risk_level' => $level, 'churn_risk_score' => $score, 'churn_reason' => 'x', 'computed_at' => now(),
            ]);
        }

        $db->table('metric_runs')->insert(['mode' => 'full', 'status' => 'completed', 'thresholds' => json_encode(['p50' => 30, 'p75' => 60, 'p90' => 90, 'sample_size' => 99, 'note' => 'ignored']), 'finished_at' => now()]);

        // daily_metrics: 40 consecutive days ending 2026-10-14, 2 orders and 200 000 net revenue per day.
        foreach (range(0, 39) as $d) {
            $date = now()->setDate(2026, 10, 14)->subDays($d)->toDateString();
            $db->table('daily_metrics')->insert([
                'date' => $date, 'jalali_date' => '1405/07/22', 'orders_count' => 2, 'revenue' => 210_000, 'refunds' => 10_000, 'net_revenue' => 200_000,
                'product_revenue' => 180_000, 'shipping_revenue' => 20_000, 'aov' => 100_000, 'customers_total' => 2, 'customers_new' => 1,
                'customers_repeat' => 1, 'revenue_new' => 100_000, 'revenue_repeat' => 100_000, 'computed_at' => now(),
            ]);
        }

        // cohorts: 1405-01 (size 10, mature p1..p2) and 1405-02 (size 3 -> too small to show).
        foreach ([['1405-01', 10], ['1405-02', 3]] as [$month, $size]) {
            foreach ([0, 1, 2] as $p) {
                $active = $p === 0 ? $size : intdiv($size, 2);
                $db->table('cohort_snapshots')->insert([
                    'cohort_month' => $month, 'period_number' => $p, 'cohort_size' => $size, 'active_customers' => $active,
                    'retention_rate' => $active / $size, 'orders_count' => $active, 'revenue' => $active * 100_000,
                    'cumulative_revenue' => ($p + 1) * 500_000, 'is_mature' => $p < 2, 'computed_at' => now(),
                ]);
            }
        }

        $catA = $db->table('product_categories')->insertGetId(['woo_category_id' => 1, 'name' => 'Dresses', 'created_at' => now(), 'updated_at' => now()]);
        $catB = $db->table('product_categories')->insertGetId(['woo_category_id' => 2, 'name' => 'Scarves', 'created_at' => now(), 'updated_at' => now()]);
        $p1 = $db->table('products')->insertGetId(['woo_product_id' => 11, 'name' => 'Linen Dress', 'status' => 'publish']);
        $p2 = $db->table('products')->insertGetId(['woo_product_id' => 12, 'name' => 'Silk Scarf', 'status' => 'publish']);
        $p3 = $db->table('products')->insertGetId(['woo_product_id' => 13, 'name' => 'Rare Coat', 'status' => 'publish']);

        foreach ([['category', $catA, $catB, 8, 2.5], ['product', $p1, $p2, 7, 3.1], ['product', $p2, $p3, 5, 1.4], ['product', $p1, $p3, 3, 9.9], ['basket', $p1, $p2, 12, 2.0]] as [$level, $a, $b, $co, $lift]) {
            $db->table('product_affinities')->insert(['level' => $level, 'entity_a_id' => $a, 'entity_b_id' => $b, 'co_customers' => $co, 'a_customers' => 10, 'b_customers' => 9, 'support' => 0.1, 'confidence' => 0.5, 'lift' => $lift, 'computed_at' => now()]);
        }

        // purchases: p1 bought by 6 customers (units 12, revenue 600 000), p2 by 5 (units 5, 250 000), p3 by 2 (hidden).
        foreach ([[$p1, range(0, 5), 2, 100_000], [$p2, range(0, 4), 1, 50_000], [$p3, [0, 1], 1, 900_000]] as [$product, $who, $items, $revenue]) {
            foreach ($who as $n) {
                $db->table('customer_product_purchases')->insert(['customer_id' => $ids[$n], 'product_id' => $product, 'orders_count' => 1, 'items_count' => $items, 'revenue' => $revenue]);
            }
        }

        $big = $db->table('segments')->insertGetId(['name' => 'Big spenders', 'type' => 'dynamic', 'rule' => json_encode(['x' => 1]), 'member_count' => 6, 'last_evaluated_at' => '2026-10-14 05:00:00+00', 'created_at' => now(), 'updated_at' => now()]);
        $tiny = $db->table('segments')->insertGetId(['name' => 'Tiny', 'type' => 'dynamic', 'rule' => json_encode(['x' => 1]), 'member_count' => 2, 'created_at' => now(), 'updated_at' => now()]);

        foreach (range(0, 5) as $n) {
            $db->table('segment_members')->insert(['segment_id' => $big, 'customer_id' => $ids[$n], 'added_at' => now()]);
        }

        foreach ([10, 11] as $n) {
            $db->table('segment_members')->insert(['segment_id' => $tiny, 'customer_id' => $ids[$n], 'added_at' => now()]);
        }

        return ['customers' => $ids, 'big_segment' => $big, 'tiny_segment' => $tiny, 'products' => [$p1, $p2, $p3], 'categories' => [$catA, $catB]];
    }
}
