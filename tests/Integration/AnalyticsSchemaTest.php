<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Integration\SchemaProbe;

function analyticsCustomer(): int
{
    return DB::table('customers')->insertGetId([
        'phone_normalized' => '989'.fake()->unique()->numerify('#########'), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function affinityRow(array $overrides = []): array
{
    return array_merge([
        'level' => 'category', 'entity_a_id' => 1, 'entity_b_id' => 2,
        'co_customers' => 25, 'a_customers' => 100, 'b_customers' => 80,
        'support' => 0.05, 'confidence' => 0.25, 'lift' => 1.5,
    ], $overrides);
}

it('has daily_metrics exactly as the PRD defines it', function () {
    expect(SchemaProbe::mismatches('daily_metrics', [
        'date' => ['date', false, null],
        'jalali_date' => ['varchar(10)', false, null],
        'orders_count' => ['int', false, null],
        'revenue' => ['bigint', false, null],
        'refunds' => ['bigint', false, null],
        'net_revenue' => ['bigint', false, null],
        'aov' => ['bigint', false, null],
        'customers_total' => ['int', false, null],
        'customers_new' => ['int', false, null],
        'customers_repeat' => ['int', false, null],
        'revenue_new' => ['bigint', false, null],
        'revenue_repeat' => ['bigint', false, null],
        'computed_at' => ['tstz', false, 'current_timestamp'],
    ]))->toBeEmpty()
        ->and(SchemaProbe::primaryKey('daily_metrics'))->toBe(['date']);
});

it('has cohort_snapshots exactly as the PRD defines it', function () {
    expect(SchemaProbe::mismatches('cohort_snapshots', [
        'id' => ['bigint', false, null],
        'cohort_month' => ['varchar(7)', false, null],
        'period_number' => ['smallint', false, null],
        'cohort_size' => ['int', false, null],
        'active_customers' => ['int', false, null],
        'retention_rate' => ['numeric(6,4)', false, null],
        'orders_count' => ['int', false, null],
        'revenue' => ['bigint', false, null],
        'cumulative_revenue' => ['bigint', false, null],
        'is_mature' => ['bool', false, 'true'],
        'computed_at' => ['tstz', false, 'current_timestamp'],
    ]))->toBeEmpty();
});

it('has product_affinities exactly as the PRD defines it', function () {
    expect(SchemaProbe::mismatches('product_affinities', [
        'id' => ['bigint', false, null],
        'level' => ['varchar(10)', false, null],
        'entity_a_id' => ['bigint', false, null],
        'entity_b_id' => ['bigint', false, null],
        'co_customers' => ['int', false, null],
        'a_customers' => ['int', false, null],
        'b_customers' => ['int', false, null],
        'support' => ['numeric(9,6)', false, null],
        'confidence' => ['numeric(9,6)', false, null],
        'lift' => ['numeric(9,4)', false, null],
        'computed_at' => ['tstz', false, 'current_timestamp'],
    ]))->toBeEmpty();
});

it('has both customer purchase aggregate tables exactly as the PRD defines them', function () {
    foreach (['customer_category_purchases' => 'category_id', 'customer_product_purchases' => 'product_id'] as $table => $entity) {
        expect(SchemaProbe::mismatches($table, [
            'customer_id' => ['bigint', false, null],
            $entity => ['bigint', false, null],
            'orders_count' => ['int', false, null],
            'items_count' => ['int', false, null],
            'revenue' => ['bigint', false, null],
            'last_bought_at' => ['tstz', true, null],
        ]))->toBeEmpty()
            ->and(SchemaProbe::primaryKey($table))->toBe(['customer_id', $entity]);
    }
});

it('keeps one cohort snapshot per cohort month and period, and defaults is_mature to true', function () {
    $row = ['cohort_month' => '1403-07', 'period_number' => 0, 'cohort_size' => 100, 'active_customers' => 100,
        'retention_rate' => 1.0, 'orders_count' => 100, 'revenue' => 5_000_000, 'cumulative_revenue' => 5_000_000];
    DB::table('cohort_snapshots')->insert($row);

    expect(DB::table('cohort_snapshots')->value('is_mature'))->toBeTrue();

    DB::table('cohort_snapshots')->insert($row);
})->throws(QueryException::class);

it('allows the same period for different cohorts and different periods for one cohort', function () {
    $base = ['cohort_size' => 10, 'active_customers' => 5, 'retention_rate' => 0.5, 'orders_count' => 5, 'revenue' => 1, 'cumulative_revenue' => 1];

    DB::table('cohort_snapshots')->insert($base + ['cohort_month' => '1403-07', 'period_number' => 1]);
    DB::table('cohort_snapshots')->insert($base + ['cohort_month' => '1403-08', 'period_number' => 1]);
    DB::table('cohort_snapshots')->insert($base + ['cohort_month' => '1403-07', 'period_number' => 2]);

    expect(DB::table('cohort_snapshots')->count())->toBe(3);
});

it('stores retention_rate with four decimals', function () {
    DB::table('cohort_snapshots')->insert(['cohort_month' => '1403-07', 'period_number' => 3, 'cohort_size' => 3, 'active_customers' => 1,
        'retention_rate' => 0.33333, 'orders_count' => 1, 'revenue' => 1, 'cumulative_revenue' => 1]);

    expect(DB::table('cohort_snapshots')->value('retention_rate'))->toBe('0.3333');
});

it('accepts each affinity level and rejects any other', function () {
    foreach (['variation', 'product', 'category', 'basket'] as $i => $level) {
        DB::table('product_affinities')->insert(affinityRow(['level' => $level, 'entity_a_id' => 10 + $i]));
    }

    expect(DB::table('product_affinities')->count())->toBe(4);
});

it('rejects an unknown affinity level', function () {
    DB::table('product_affinities')->insert(affinityRow(['level' => 'brand']));
})->throws(QueryException::class);

it('rejects an affinity between an entity and itself', function () {
    DB::table('product_affinities')->insert(affinityRow(['entity_a_id' => 5, 'entity_b_id' => 5]));
})->throws(QueryException::class);

it('keeps one affinity row per level and entity pair', function () {
    DB::table('product_affinities')->insert(affinityRow());

    DB::table('product_affinities')->insert(affinityRow());
})->throws(QueryException::class);

it('has the descending lift index the top-affinity queries need', function () {
    expect(SchemaProbe::hasIndexOn('product_affinities', 'level, entity_a_id, lift DESC'))->toBeTrue()
        ->and(SchemaProbe::hasUniqueOn('product_affinities', 'level', 'entity_a_id', 'entity_b_id'))->toBeTrue();
});

it('keeps support/confidence at six decimals and lift at four', function () {
    DB::table('product_affinities')->insert(affinityRow(['support' => 0.1234567, 'confidence' => 0.7654321, 'lift' => 2.34567]));
    $row = DB::table('product_affinities')->first();

    expect($row->support)->toBe('0.123457')->and($row->confidence)->toBe('0.765432')->and($row->lift)->toBe('2.3457');
});

it('cascades customer purchase aggregates with the customer and the product/category', function () {
    $customer = analyticsCustomer();
    $category = DB::table('product_categories')->insertGetId(['woo_category_id' => 1, 'name' => 'Shirts']);
    $product = DB::table('products')->insertGetId(['woo_product_id' => 1, 'name' => 'Shirt', 'status' => 'publish', 'created_at' => now(), 'updated_at' => now()]);

    DB::table('customer_category_purchases')->insert(['customer_id' => $customer, 'category_id' => $category, 'orders_count' => 1, 'items_count' => 2, 'revenue' => 10]);
    DB::table('customer_product_purchases')->insert(['customer_id' => $customer, 'product_id' => $product, 'orders_count' => 1, 'items_count' => 2, 'revenue' => 10]);

    expect(SchemaProbe::foreignKey('customer_category_purchases', 'category_id'))->toBe(['ref_table' => 'product_categories', 'delete_rule' => 'CASCADE'])
        ->and(SchemaProbe::foreignKey('customer_product_purchases', 'product_id'))->toBe(['ref_table' => 'products', 'delete_rule' => 'CASCADE']);

    DB::table('products')->where('id', $product)->delete();
    DB::table('product_categories')->where('id', $category)->delete();
    expect(DB::table('customer_category_purchases')->count())->toBe(0)->and(DB::table('customer_product_purchases')->count())->toBe(0);

});

it('removes purchase aggregates when their customer is deleted', function () {
    $customer = analyticsCustomer();
    $product = DB::table('products')->insertGetId(['woo_product_id' => 2, 'name' => 'Shirt', 'status' => 'publish', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('customer_product_purchases')->insert(['customer_id' => $customer, 'product_id' => $product, 'orders_count' => 1, 'items_count' => 1, 'revenue' => 1]);

    DB::table('customers')->where('id', $customer)->delete();

    expect(SchemaProbe::foreignKey('customer_product_purchases', 'customer_id'))->toBe(['ref_table' => 'customers', 'delete_rule' => 'CASCADE'])
        ->and(DB::table('customer_product_purchases')->count())->toBe(0);
});

it('lists a customer once per product and per category', function () {
    $customer = analyticsCustomer();
    $product = DB::table('products')->insertGetId(['woo_product_id' => 3, 'name' => 'Shirt', 'status' => 'publish', 'created_at' => now(), 'updated_at' => now()]);
    $row = ['customer_id' => $customer, 'product_id' => $product, 'orders_count' => 1, 'items_count' => 1, 'revenue' => 1];
    DB::table('customer_product_purchases')->insert($row);

    DB::table('customer_product_purchases')->insert($row);
})->throws(QueryException::class);
