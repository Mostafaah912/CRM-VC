<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function insertProduct(array $overrides = []): int
{
    return DB::table('products')->insertGetId(array_merge([
        'woo_product_id' => fake()->unique()->numberBetween(1, 9_000_000),
        'name' => 'Linen shirt',
        'status' => 'publish',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function insertVariation(int $productId, array $overrides = []): int
{
    return DB::table('product_variations')->insertGetId(array_merge([
        'product_id' => $productId,
        'status' => 'publish',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('defaults a product to type simple and stamps synced_at', function () {
    $row = DB::table('products')->find(insertProduct());

    expect($row->type)->toBe('simple')->and($row->synced_at)->not->toBeNull();
});

it('enforces one product per Woo product id', function () {
    insertProduct(['woo_product_id' => 501]);

    expect(fn () => insertProduct(['woo_product_id' => 501]))->toThrow(QueryException::class);
});

it('rejects an unknown product type or status', function (string $column, string $value) {
    insertProduct([$column => $value]);
})->with([
    ['type', 'bundle'],
    ['status', 'archived'],
])->throws(QueryException::class);

it('accepts every product type and status Woo core uses', function () {
    foreach (['simple', 'variable', 'grouped', 'external'] as $type) {
        insertProduct(['type' => $type]);
    }
    foreach (['publish', 'draft', 'pending', 'private'] as $status) {
        insertProduct(['status' => $status]);
    }

    expect(DB::table('products')->count())->toBe(8);
});

it('enforces one category per Woo category id and nulls the parent when a parent is deleted', function () {
    $parent = DB::table('product_categories')->insertGetId(['woo_category_id' => 10, 'name' => 'Women']);
    $child = DB::table('product_categories')->insertGetId(['woo_category_id' => 11, 'name' => 'Dresses', 'parent_id' => $parent]);

    DB::table('product_categories')->where('id', $parent)->delete();

    expect(DB::table('product_categories')->where('id', $child)->value('parent_id'))->toBeNull();
});

it('rejects a duplicate Woo category id', function () {
    DB::table('product_categories')->insert(['woo_category_id' => 10, 'name' => 'A']);

    DB::table('product_categories')->insert(['woo_category_id' => 10, 'name' => 'B']);
})->throws(QueryException::class);

it('links a product to many categories exactly once per pair', function () {
    $product = insertProduct();
    $category = DB::table('product_categories')->insertGetId(['woo_category_id' => 1, 'name' => 'Shirts']);

    DB::table('product_category_product')->insert(['product_id' => $product, 'category_id' => $category]);

    expect(fn () => DB::table('product_category_product')->insert(['product_id' => $product, 'category_id' => $category]))
        ->toThrow(QueryException::class);
});

it('removes pivot rows when a product or category is deleted', function () {
    $product = insertProduct();
    $category = DB::table('product_categories')->insertGetId(['woo_category_id' => 1, 'name' => 'Shirts']);
    DB::table('product_category_product')->insert(['product_id' => $product, 'category_id' => $category]);

    DB::table('products')->where('id', $product)->delete();

    expect(DB::table('product_category_product')->count())->toBe(0);
});

it('defaults variation attributes to an empty JSON object and keeps JSONB attributes', function () {
    $product = insertProduct(['type' => 'variable']);
    $plain = insertVariation($product);
    $sized = insertVariation($product, ['attributes' => json_encode(['size' => 'L', 'color' => 'مشکی'])]);

    $row = DB::selectOne("select attributes::text as a, attributes->>'size' as size from product_variations where id = ?", [$sized]);

    expect(DB::selectOne('select attributes::text as a from product_variations where id = ?', [$plain])->a)->toBe('{}')
        ->and($row->size)->toBe('L');
});

it('enforces a unique SKU across variations but allows many NULL SKUs', function () {
    $product = insertProduct();

    insertVariation($product);
    insertVariation($product);
    insertVariation($product, ['sku' => 'HM-1']);

    expect(fn () => insertVariation($product, ['sku' => 'HM-1']))->toThrow(QueryException::class);
});

it('enforces a unique Woo variation id but allows many NULLs', function () {
    $product = insertProduct();

    insertVariation($product);
    insertVariation($product);
    insertVariation($product, ['woo_variation_id' => 900]);

    expect(fn () => insertVariation($product, ['woo_variation_id' => 900]))->toThrow(QueryException::class);
});

it('rejects an unknown variation status', function () {
    insertVariation(insertProduct(), ['status' => 'gone']);
})->throws(QueryException::class);

it('stores a variation price as an integer Toman (bigint), never a float', function () {
    $product = insertProduct();
    $id = insertVariation($product, ['price' => 403880]);

    $column = DB::selectOne("select data_type from information_schema.columns where table_name = 'product_variations' and column_name = 'price'");

    expect($column->data_type)->toBe('bigint')
        ->and(DB::table('product_variations')->where('id', $id)->value('price'))->toBe(403880);
});

it('removes variations when their product is deleted', function () {
    $product = insertProduct();
    insertVariation($product);

    DB::table('products')->where('id', $product)->delete();

    expect(DB::table('product_variations')->count())->toBe(0);
});

it('has the trigram index on product name and the partial unique SKU index', function () {
    $defs = collect(DB::select("select indexname, indexdef from pg_indexes where tablename in ('products', 'product_variations')"))
        ->keyBy('indexname');

    expect($defs['products_name_trgm_index']->indexdef)->toContain('gin_trgm_ops')
        ->and($defs['product_variations_sku_unique']->indexdef)->toContain('UNIQUE')->toContain('WHERE (sku IS NOT NULL)');
});

it('creates product_costs empty, with one cost per variation per effective date', function () {
    expect(DB::table('product_costs')->count())->toBe(0);

    $variation = insertVariation(insertProduct());
    $row = ['variation_id' => $variation, 'unit_cost' => 150000, 'effective_from' => '2026-01-01'];

    DB::table('product_costs')->insert($row);

    expect(DB::table('product_costs')->value('source'))->toBe('manual')
        ->and(fn () => DB::table('product_costs')->insert($row))->toThrow(QueryException::class);
});

it('protects manual cost data: a variation with costs cannot be deleted', function () {
    $variation = insertVariation(insertProduct());
    DB::table('product_costs')->insert(['variation_id' => $variation, 'unit_cost' => 1, 'effective_from' => '2026-01-01']);

    DB::table('product_variations')->where('id', $variation)->delete();
})->throws(QueryException::class);

it('rejects an unknown cost source', function () {
    $variation = insertVariation(insertProduct());

    DB::table('product_costs')->insert(['variation_id' => $variation, 'unit_cost' => 1, 'effective_from' => '2026-01-01', 'source' => 'guess']);
})->throws(QueryException::class);

it('stores every catalog timestamp as timestamptz', function () {
    $rows = DB::select("
        select table_name, column_name from information_schema.columns
        where table_schema = 'public' and data_type = 'timestamp without time zone'
          and table_name in ('product_categories', 'products', 'product_category_product', 'product_variations', 'product_costs')
    ");

    expect($rows)->toBeEmpty();
});
