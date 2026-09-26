<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Events\ProductSynced;
use App\Modules\Catalog\Exceptions\CatalogIntegrityException;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Models\ProductVariation;
use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Catalog\Services\CategoryInput;
use App\Modules\Catalog\Services\ProductInput;
use App\Modules\Catalog\Services\VariationInput;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
| P2-05 — CatalogService: Woo -> local catalog upserts (PRD §07/§09). Woo is the source of truth, so
| this mirrors it: identity is always the woo_*_id, a repeat changes nothing, and nothing is ever
| deleted. Where the spec defines no policy (a SKU already taken, a variation under another product)
| the service does not choose one: it refuses with CatalogIntegrityException and writes nothing.
| Real PostgreSQL only; every name, id and SKU is synthetic.
*/

/** @var list<array{level: string, message: string, context: array<string, mixed>}> $catalogLogs */
$GLOBALS['catalog_logs'] = [];

beforeEach(function () {
    config(['logging.default' => 'null']);
    $GLOBALS['catalog_logs'] = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) {
        $GLOBALS['catalog_logs'][] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
    });
});

function catalog(): CatalogService
{
    return app(CatalogService::class);
}

function warnings(): array
{
    return array_values(array_filter($GLOBALS['catalog_logs'], fn (array $l) => $l['level'] === 'warning'));
}

function category(int $woo, string $name = 'دسته', ?string $slug = null, ?int $parent = null): CategoryInput
{
    return new CategoryInput($woo, $name, $slug, $parent);
}

function variation(int $woo, ?string $sku = null, ?int $price = 100000, string $status = 'publish', array $attributes = []): VariationInput
{
    return new VariationInput($woo, $sku, $price, $status, $attributes);
}

/** @param list<int> $categories @param list<VariationInput> $variations */
function product(int $woo = 101, string $name = 'محصول آزمایشی', string $type = 'simple', string $status = 'publish', array $categories = [], array $variations = [], ?CarbonImmutable $createdAtWoo = null, ?string $slug = 'synthetic', ?string $sku = null, ?int $price = null): ProductInput
{
    return new ProductInput($woo, $name, $slug, $type, $status, $createdAtWoo, $categories, $variations, $sku, $price);
}

function linkedCategories(Product $product): array
{
    return $product->categories()->pluck('woo_category_id')->sort()->values()->all();
}

// =================================================================== categories

it('creates one category for a new Woo category id', function () {
    catalog()->upsertCategories([category(31, 'پوشاک آزمایشی', 'synthetic-apparel')]);

    $row = ProductCategory::sole();
    expect([$row->woo_category_id, $row->name, $row->slug, $row->parent_id])->toBe([31, 'پوشاک آزمایشی', 'synthetic-apparel', null]);
});

it('updates the existing category instead of duplicating it — identity is the woo_category_id only', function () {
    catalog()->upsertCategories([category(31, 'قدیمی', 'old-slug')]);
    $id = ProductCategory::sole()->id;

    catalog()->upsertCategories([category(31, 'جدید', 'new-slug')]);

    $row = ProductCategory::sole();
    expect([$row->id, $row->name, $row->slug])->toBe([$id, 'جدید', 'new-slug']);
});

it('keeps different Woo ids apart even when their names are identical', function () {
    catalog()->upsertCategories([category(31, 'همنام'), category(32, 'همنام')]);

    expect(ProductCategory::count())->toBe(2);
});

it('is idempotent: repeating the same batch changes nothing, not even updated_at', function () {
    $batch = [category(31, 'ریشه', 'root'), category(32, 'فرزند', 'child', 31)];
    $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00:00', 'UTC'));
    catalog()->upsertCategories($batch);
    $before = ProductCategory::orderBy('woo_category_id')->get()->map->only(['id', 'woo_category_id', 'name', 'slug', 'parent_id', 'updated_at'])->all();

    $this->travel(2)->hours();
    catalog()->upsertCategories($batch);
    catalog()->upsertCategories($batch);

    $after = ProductCategory::orderBy('woo_category_id')->get()->map->only(['id', 'woo_category_id', 'name', 'slug', 'parent_id', 'updated_at'])->all();
    expect(ProductCategory::count())->toBe(2)->and($after)->toEqual($before);
});

it('persists the parent relationship', function () {
    catalog()->upsertCategories([category(31, 'ریشه'), category(32, 'فرزند', null, 31)]);

    $child = ProductCategory::where('woo_category_id', 32)->sole();
    expect($child->parent?->woo_category_id)->toBe(31)
        ->and(ProductCategory::where('woo_category_id', 31)->sole()->children)->toHaveCount(1);
});

it('resolves a parent that arrives AFTER its child in the same batch', function () {
    catalog()->upsertCategories([category(32, 'فرزند', null, 31), category(31, 'ریشه')]);

    expect(ProductCategory::where('woo_category_id', 32)->sole()->parent?->woo_category_id)->toBe(31)
        ->and(ProductCategory::count())->toBe(2);
});

it('never invents a parent: an unknown parent leaves parent_id null, creates nothing, and warns', function () {
    catalog()->upsertCategories([category(32, 'یتیم', null, 99)]);

    $row = ProductCategory::sole();
    expect($row->parent_id)->toBeNull()
        ->and(ProductCategory::where('woo_category_id', 99)->exists())->toBeFalse()
        ->and(warnings())->toHaveCount(1)
        ->and(warnings()[0]['context'])->toMatchArray(['woo_category_id' => 32, 'parent_woo_category_id' => 99]);
});

it('links the parent on a later sync once the parent exists', function () {
    catalog()->upsertCategories([category(32, 'فرزند', null, 31)]);
    catalog()->upsertCategories([category(31, 'ریشه'), category(32, 'فرزند', null, 31)]);

    expect(ProductCategory::where('woo_category_id', 32)->sole()->parent?->woo_category_id)->toBe(31);
});

it('clears the parent when Woo makes the category a root, and drops a parent Woo now points elsewhere', function () {
    catalog()->upsertCategories([category(31, 'الف'), category(32, 'ب'), category(33, 'ج', null, 31)]);

    catalog()->upsertCategories([category(33, 'ج', null, 32)]);
    expect(ProductCategory::where('woo_category_id', 33)->sole()->parent?->woo_category_id)->toBe(32);

    catalog()->upsertCategories([category(33, 'ج', null, null)]);
    expect(ProductCategory::where('woo_category_id', 33)->sole()->parent_id)->toBeNull();

    catalog()->upsertCategories([category(33, 'ج', null, 31)]);
    catalog()->upsertCategories([category(33, 'ج', null, 777)]);
    expect(ProductCategory::where('woo_category_id', 33)->sole()->parent_id)->toBeNull();
});

it('collapses a category repeated inside one batch into one row', function () {
    catalog()->upsertCategories([category(31, 'اول'), category(31, 'دوم')]);

    expect(ProductCategory::sole()->name)->toBe('دوم');
});

it('never deletes a category that is missing from a later batch', function () {
    catalog()->upsertCategories([category(31), category(32)]);
    catalog()->upsertCategories([category(31)]);

    expect(ProductCategory::count())->toBe(2);
});

it('writes a category batch atomically: one bad row rolls the whole batch back', function () {
    expect(fn () => catalog()->upsertCategories([category(31, 'خوب'), category(32, str_repeat('ا', 400))]))
        ->toThrow(QueryException::class);

    expect(ProductCategory::count())->toBe(0);
});

it('is backed by the database: a second row for a woo_category_id is refused', function () {
    catalog()->upsertCategories([category(31)]);

    expect(fn () => DB::transaction(fn () => DB::table('product_categories')->insert([
        'woo_category_id' => 31, 'name' => 'تکراری', 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(UniqueConstraintViolationException::class);
});

// ===================================================================== products

it('creates one product with Woo\'s own created date and a local synced_at', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00:00', 'UTC'));

    $id = catalog()->upsertProduct(product(101, 'تی‌شرت آزمایشی', 'simple', 'publish', createdAtWoo: CarbonImmutable::parse('2026-01-05 06:00:00', 'UTC'), slug: 'synthetic-tee'));

    $row = Product::sole();
    expect($id)->toBe($row->id)
        ->and([$row->woo_product_id, $row->name, $row->slug, $row->type, $row->status])->toBe([101, 'تی‌شرت آزمایشی', 'synthetic-tee', ProductType::Simple, ProductStatus::Publish])
        ->and($row->created_at_woo?->utc()->format('Y-m-d H:i:s'))->toBe('2026-01-05 06:00:00')
        ->and($row->synced_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-06-01 10:00:00');
});

it('updates the existing product, keeps one row, and moves only synced_at forward — never Woo\'s created date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00:00', 'UTC'));
    $woo = CarbonImmutable::parse('2026-01-05 06:00:00', 'UTC');
    $id = catalog()->upsertProduct(product(101, 'قدیمی', createdAtWoo: $woo));
    $localCreated = Product::sole()->created_at;

    $this->travelTo(CarbonImmutable::parse('2026-06-02 12:00:00', 'UTC'));
    $sameId = catalog()->upsertProduct(product(101, 'جدید', 'variable', 'draft', createdAtWoo: $woo));

    $row = Product::sole();
    expect($sameId)->toBe($id)
        ->and([$row->name, $row->type, $row->status])->toBe(['جدید', ProductType::Variable, ProductStatus::Draft])
        ->and($row->created_at_woo?->utc()->format('Y-m-d H:i:s'))->toBe('2026-01-05 06:00:00')
        ->and($row->created_at->equalTo($localCreated))->toBeTrue()
        ->and($row->synced_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-06-02 12:00:00');
});

it('does not erase Woo\'s created date when a later payload has none, but takes a new one', function () {
    catalog()->upsertProduct(product(101, createdAtWoo: CarbonImmutable::parse('2026-01-05 06:00:00', 'UTC')));

    catalog()->upsertProduct(product(101, createdAtWoo: null));
    expect(Product::sole()->created_at_woo?->utc()->format('Y-m-d'))->toBe('2026-01-05');

    catalog()->upsertProduct(product(101, createdAtWoo: CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC')));
    expect(Product::sole()->created_at_woo?->utc()->format('Y-m-d'))->toBe('2026-02-01');
});

it('is idempotent: repeating a product never creates a second row, link or variation', function () {
    catalog()->upsertCategories([category(31), category(32)]);
    $input = product(103, 'متغیر', 'variable', categories: [31, 32], variations: [variation(1031, 'SYN-S'), variation(1032, 'SYN-M')]);

    $ids = array_map(fn () => catalog()->upsertProduct($input), range(1, 4));

    expect(array_unique($ids))->toHaveCount(1)
        ->and(Product::count())->toBe(1)
        ->and(ProductVariation::count())->toBe(2)
        ->and(DB::table('product_category_product')->count())->toBe(2);
});

it('keeps different Woo products apart and never deletes one missing from a later call', function () {
    catalog()->upsertProduct(product(101));
    catalog()->upsertProduct(product(102));
    catalog()->upsertProduct(product(102));

    expect(Product::pluck('woo_product_id')->sort()->values()->all())->toBe([101, 102]);
});

it('is backed by the database: a second row for a woo_product_id is refused', function () {
    catalog()->upsertProduct(product(101));

    expect(fn () => DB::transaction(fn () => DB::table('products')->insert([
        'woo_product_id' => 101, 'name' => 'تکراری', 'type' => 'simple', 'status' => 'publish', 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(UniqueConstraintViolationException::class);
});

it('stores the core Woo types and statuses exactly', function (string $type, ProductType $expectedType, string $status, ProductStatus $expectedStatus) {
    catalog()->upsertProduct(product(101, type: $type, status: $status));

    $row = Product::sole();
    expect([$row->type, $row->status])->toBe([$expectedType, $expectedStatus])->and(warnings())->toBe([]);
})->with([
    ['simple', ProductType::Simple, 'publish', ProductStatus::Publish],
    ['variable', ProductType::Variable, 'draft', ProductStatus::Draft],
    ['grouped', ProductType::Grouped, 'pending', ProductStatus::Pending],
    ['external', ProductType::External, 'private', ProductStatus::Private],
]);

it('maps a type or status outside the core set instead of rejecting the product, and says so', function (string $type, ProductType $storedType, string $status, ProductStatus $storedStatus) {
    catalog()->upsertProduct(product(101, type: $type, status: $status));

    $row = Product::sole();
    expect([$row->type, $row->status])->toBe([$storedType, $storedStatus])
        ->and(warnings())->toHaveCount(2)
        ->and(warnings()[0]['context'])->toMatchArray(['woo_product_id' => 101, 'woo_type' => $type, 'stored_as' => $storedType->value])
        ->and(warnings()[1]['context'])->toMatchArray(['woo_id' => 101, 'woo_status' => $status, 'stored_as' => $storedStatus->value]);
})->with([
    'plugin type, scheduled' => ['variable-subscription', ProductType::Simple, 'future', ProductStatus::Draft],
    'bundle, trashed' => ['bundle', ProductType::Simple, 'trash', ProductStatus::Draft],
]);

it('announces a synced product once, after commit, with ids only', function () {
    Event::fake([ProductSynced::class]);

    $id = catalog()->upsertProduct(product(101, 'نام نباید در رویداد باشد'));

    Event::assertDispatchedTimes(ProductSynced::class, 1);
    Event::assertDispatched(ProductSynced::class, fn (ProductSynced $e) => [$e->productId, $e->wooProductId] === [$id, 101]);
    foreach ((new ReflectionClass(ProductSynced::class))->getProperties() as $property) {
        expect((string) $property->getType())->toBe('int');
    }
});

// ================================================== product <-> category links

it('links a product to several categories', function () {
    catalog()->upsertCategories([category(31), category(32), category(33)]);

    catalog()->upsertProduct(product(101, categories: [31, 32]));

    expect(linkedCategories(Product::sole()))->toBe([31, 32]);
});

it('never duplicates a link: repeated syncs keep exactly one pivot row per pair', function () {
    catalog()->upsertCategories([category(31), category(32)]);

    foreach (range(1, 5) as $ignored) {
        catalog()->upsertProduct(product(101, categories: [31, 32, 31]));
    }

    expect(DB::table('product_category_product')->count())->toBe(2);
});

it('mirrors Woo: the category set is REPLACED, and a dropped category is unlinked but not deleted', function () {
    catalog()->upsertCategories([category(31), category(32), category(33)]);
    catalog()->upsertProduct(product(101, categories: [31, 32]));

    catalog()->upsertProduct(product(101, categories: [32, 33]));

    expect(linkedCategories(Product::sole()))->toBe([32, 33])
        ->and(ProductCategory::count())->toBe(3);

    catalog()->upsertProduct(product(101, categories: []));
    expect(linkedCategories(Product::sole()))->toBe([]);
});

it('leaves other products\' links alone', function () {
    catalog()->upsertCategories([category(31), category(32)]);
    catalog()->upsertProduct(product(101, categories: [31, 32]));

    catalog()->upsertProduct(product(102, categories: [31]));
    catalog()->upsertProduct(product(102, categories: []));

    expect(linkedCategories(Product::where('woo_product_id', 101)->sole()))->toBe([31, 32]);
});

it('skips a category that is not synced yet, still syncs the product, and warns with ids only', function () {
    catalog()->upsertCategories([category(31)]);

    catalog()->upsertProduct(product(101, categories: [31, 998, 999]));

    expect(linkedCategories(Product::sole()))->toBe([31])
        ->and(ProductCategory::count())->toBe(1)
        ->and(warnings())->toHaveCount(1)
        ->and(warnings()[0]['context'])->toMatchArray(['woo_product_id' => 101, 'missing_woo_category_ids' => [998, 999]]);
});

// ================================================================== variations

it('creates a variation under the right product with integer price, status and attributes', function () {
    catalog()->upsertProduct(product(103, type: 'variable', variations: [
        variation(1031, 'SYN-SHIRT-103-S', 250000, 'publish', ['سایز' => 'S', 'رنگ' => 'مشکی']),
    ]));

    $row = ProductVariation::sole();
    expect($row->product_id)->toBe(Product::sole()->id)
        ->and([$row->woo_variation_id, $row->sku, $row->price, $row->status])->toBe([1031, 'SYN-SHIRT-103-S', 250000, ProductStatus::Publish])
        ->and($row->getAttribute('attributes'))->toEqual(['سایز' => 'S', 'رنگ' => 'مشکی']) // jsonb does not keep key order
        ->and(is_int($row->price))->toBeTrue();
});

it('updates an existing variation by woo_variation_id and keeps its local id', function () {
    catalog()->upsertProduct(product(103, variations: [variation(1031, 'SYN-OLD', 250000, 'publish', ['سایز' => 'S'])]));
    $id = ProductVariation::sole()->id;

    catalog()->upsertProduct(product(103, variations: [variation(1031, 'SYN-NEW', 300000, 'private', ['سایز' => 'M'])]));

    $row = ProductVariation::sole();
    expect([$row->id, $row->sku, $row->price, $row->status])->toBe([$id, 'SYN-NEW', 300000, ProductStatus::Private])
        ->and($row->getAttribute('attributes'))->toBe(['سایز' => 'M']);
});

it('is idempotent for variations, including synced_at moving forward', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00:00', 'UTC'));
    $input = product(103, variations: [variation(1031, 'SYN-S'), variation(1032, 'SYN-M')]);
    catalog()->upsertProduct($input);
    $ids = ProductVariation::orderBy('woo_variation_id')->pluck('id')->all();

    $this->travel(1)->day();
    catalog()->upsertProduct($input);
    catalog()->upsertProduct($input);

    expect(ProductVariation::orderBy('woo_variation_id')->pluck('id')->all())->toBe($ids)
        ->and(ProductVariation::count())->toBe(2)
        ->and(ProductVariation::first()->synced_at->utc()->format('Y-m-d'))->toBe('2026-06-02');
});

it('allows many variations with no SKU, no price and no attributes', function () {
    catalog()->upsertProduct(product(103, variations: [variation(1031, null, null), variation(1032, null, null), variation(1033, null, null)]));

    expect(ProductVariation::whereNull('sku')->count())->toBe(3)
        ->and(ProductVariation::whereNull('price')->count())->toBe(3)
        ->and(ProductVariation::first()->getAttribute('attributes'))->toBe([]);
});

it('stores attributes deterministically, whatever order they arrive in', function () {
    catalog()->upsertProduct(product(103, variations: [variation(1031, 'A', attributes: ['سایز' => 'S', 'رنگ' => 'مشکی'])]));
    $first = DB::table('product_variations')->value('attributes');

    catalog()->upsertProduct(product(103, variations: [variation(1031, 'A', attributes: ['رنگ' => 'مشکی', 'سایز' => 'S'])]));

    expect(DB::table('product_variations')->value('attributes'))->toBe($first)
        ->and(ProductVariation::sole()->getAttribute('attributes'))->toEqual(['سایز' => 'S', 'رنگ' => 'مشکی']);
});

it('keeps prices as whole Toman, including very large ones', function () {
    catalog()->upsertProduct(product(103, variations: [variation(1031, 'BIG', 11340000000)]));

    $price = ProductVariation::sole()->price;
    expect($price)->toBe(11340000000)->and(is_int($price))->toBeTrue();
});

it('attaches every variation to its own product and to no other', function () {
    catalog()->upsertProduct(product(103, type: 'variable', variations: [variation(1031, 'A-1'), variation(1032, 'A-2'), variation(1033, 'A-3')]));
    catalog()->upsertProduct(product(104, type: 'variable', variations: [variation(1041, 'B-1')]));

    $a = Product::where('woo_product_id', 103)->sole();
    $b = Product::where('woo_product_id', 104)->sole();
    expect($a->variations()->pluck('woo_variation_id')->sort()->values()->all())->toBe([1031, 1032, 1033])
        ->and($b->variations()->pluck('woo_variation_id')->all())->toBe([1041]);
});

it('is backed by the database: a second row for a woo_variation_id is refused', function () {
    catalog()->upsertProduct(product(103, variations: [variation(1031, 'A')]));

    expect(fn () => DB::transaction(fn () => DB::table('product_variations')->insert([
        'product_id' => Product::sole()->id, 'woo_variation_id' => 1031, 'status' => 'publish', 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(UniqueConstraintViolationException::class);
});

it('never deletes a variation missing from a later call', function () {
    catalog()->upsertProduct(product(103, variations: [variation(1031, 'A'), variation(1032, 'B')]));

    catalog()->upsertProduct(product(103, variations: [variation(1031, 'A')]));

    expect(ProductVariation::count())->toBe(2);
});

it('maps a variation status outside the core set instead of rejecting it', function () {
    catalog()->upsertProduct(product(103, variations: [variation(1031, 'A', status: 'trash')]));

    expect(ProductVariation::sole()->status)->toBe(ProductStatus::Draft)
        ->and(warnings()[0]['context'])->toMatchArray(['entity' => 'variation', 'woo_id' => 1031, 'woo_status' => 'trash']);
});

// ========================================================== integrity / SKU

it('refuses a SKU another variation already owns, corrupting neither', function () {
    catalog()->upsertProduct(product(103, variations: [variation(1031, 'SYN-TAKEN', 250000)]));
    $before = ProductVariation::sole()->only(['id', 'woo_variation_id', 'sku', 'price']);

    try {
        catalog()->upsertProduct(product(104, variations: [variation(1041, 'SYN-TAKEN', 999)]));
        $this->fail('Expected CatalogIntegrityException');
    } catch (CatalogIntegrityException $e) {
        expect($e->reason)->toBe(CatalogIntegrityException::SKU_CONFLICT)
            ->and($e->getMessage())->toContain('1041')->toContain('SYN-TAKEN');
    }

    expect(ProductVariation::sole()->only(['id', 'woo_variation_id', 'sku', 'price']))->toBe($before)
        ->and(Product::count())->toBe(1);
});

it('does not mistake a variation\'s own SKU for a conflict', function () {
    catalog()->upsertProduct(product(103, variations: [variation(1031, 'SYN-OWN')]));

    catalog()->upsertProduct(product(103, variations: [variation(1031, 'SYN-OWN', 1)]));

    expect(ProductVariation::sole()->price)->toBe(1);
});

it('lets a SKU be reused once its previous owner has moved on', function () {
    catalog()->upsertProduct(product(103, variations: [variation(1031, 'SYN-X')]));
    catalog()->upsertProduct(product(103, variations: [variation(1031, 'SYN-Y')]));

    catalog()->upsertProduct(product(104, variations: [variation(1041, 'SYN-X')]));

    expect(ProductVariation::where('sku', 'SYN-X')->sole()->woo_variation_id)->toBe(1041);
});

it('refuses the same SKU twice inside one payload and writes nothing', function () {
    expect(fn () => catalog()->upsertProduct(product(103, variations: [variation(1031, 'SYN-DUP'), variation(1032, 'SYN-DUP')])))
        ->toThrow(CatalogIntegrityException::class);

    expect(Product::count())->toBe(0)->and(ProductVariation::count())->toBe(0);
});

it('is backed by the database: the partial unique index refuses a duplicate SKU but allows many NULLs', function () {
    catalog()->upsertProduct(product(103, variations: [variation(1031, 'SYN-A'), variation(1032, null), variation(1033, null)]));
    $productId = Product::sole()->id;

    expect(fn () => DB::transaction(fn () => DB::table('product_variations')->insert([
        'product_id' => $productId, 'woo_variation_id' => 5000, 'sku' => 'SYN-A', 'status' => 'publish', 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(UniqueConstraintViolationException::class);

    DB::table('product_variations')->insert(['product_id' => $productId, 'woo_variation_id' => 5001, 'sku' => null, 'status' => 'publish', 'created_at' => now(), 'updated_at' => now()]);
    expect(ProductVariation::whereNull('sku')->count())->toBe(3);
});

it('refuses a Woo variation id that arrives under a different product — never moves it', function () {
    catalog()->upsertProduct(product(103, variations: [variation(1031, 'SYN-S', 250000)]));
    $original = ProductVariation::sole();

    try {
        catalog()->upsertProduct(product(104, name: 'محصول دیگر', variations: [variation(1031, 'SYN-S', 1)]));
        $this->fail('Expected CatalogIntegrityException');
    } catch (CatalogIntegrityException $e) {
        expect($e->reason)->toBe(CatalogIntegrityException::VARIATION_PARENT_MISMATCH);
    }

    $after = ProductVariation::sole();
    expect([$after->product_id, $after->price])->toBe([$original->product_id, 250000])
        ->and(Product::where('woo_product_id', 104)->exists())->toBeFalse();
});

it('writes a product, its links and its variations as one unit: a failure leaves nothing half-written', function () {
    Event::fake([ProductSynced::class]);
    catalog()->upsertCategories([category(31)]);
    catalog()->upsertProduct(product(100, variations: [variation(1001, 'SYN-TAKEN')]));
    $variationsBefore = ProductVariation::count();

    expect(fn () => catalog()->upsertProduct(product(103, 'جدید', categories: [31], variations: [variation(1031, 'SYN-OK'), variation(1032, 'SYN-TAKEN')])))
        ->toThrow(CatalogIntegrityException::class);

    expect(Product::where('woo_product_id', 103)->exists())->toBeFalse()
        ->and(ProductVariation::count())->toBe($variationsBefore)
        ->and(DB::table('product_category_product')->count())->toBe(0);
    Event::assertDispatchedTimes(ProductSynced::class, 1);
});

// ================================== simple product sku/price (P6 decision, not PRD §09's literal schema)

it('stores a simple product\'s own sku and price', function () {
    catalog()->upsertProduct(product(101, sku: 'HMP-101', price: 250000));

    $row = Product::sole();
    expect($row->sku)->toBe('HMP-101')->and($row->price)->toBe(250000)->and(is_int($row->price))->toBeTrue();
});

it('allows many products with no sku/price at all', function () {
    catalog()->upsertProduct(product(101));
    catalog()->upsertProduct(product(102));

    expect(Product::whereNull('sku')->count())->toBe(2);
});

it('updates an existing product\'s sku/price on a later sync', function () {
    catalog()->upsertProduct(product(101, sku: 'HMP-OLD', price: 100));

    catalog()->upsertProduct(product(101, sku: 'HMP-NEW', price: 200));

    $row = Product::sole();
    expect([$row->sku, $row->price])->toBe(['HMP-NEW', 200]);
});

it('does not mistake a product\'s own sku for a conflict with itself', function () {
    catalog()->upsertProduct(product(101, sku: 'HMP-SAME'));

    catalog()->upsertProduct(product(101, sku: 'HMP-SAME', price: 999));

    expect(Product::sole()->price)->toBe(999);
});

it('refuses a product sku another product already owns, corrupting neither', function () {
    catalog()->upsertProduct(product(101, sku: 'HMP-TAKEN'));
    $before = Product::sole()->only(['id', 'woo_product_id', 'sku']);

    try {
        catalog()->upsertProduct(product(102, sku: 'HMP-TAKEN'));
        $this->fail('Expected CatalogIntegrityException');
    } catch (CatalogIntegrityException $e) {
        expect($e->reason)->toBe(CatalogIntegrityException::SKU_CONFLICT)
            ->and($e->getMessage())->toContain('102')->toContain('HMP-TAKEN');
    }

    expect(Product::count())->toBe(1)->and(Product::sole()->only(['id', 'woo_product_id', 'sku']))->toBe($before);
});

it('refuses a product sku a variation already owns', function () {
    catalog()->upsertProduct(product(103, type: 'variable', variations: [variation(1031, 'V-TAKEN')]));

    expect(fn () => catalog()->upsertProduct(product(101, sku: 'V-TAKEN')))
        ->toThrow(CatalogIntegrityException::class);
    expect(Product::where('woo_product_id', 101)->exists())->toBeFalse();
});

it('refuses a variation sku a product already owns', function () {
    catalog()->upsertProduct(product(101, sku: 'P-TAKEN'));

    expect(fn () => catalog()->upsertProduct(product(103, type: 'variable', variations: [variation(1031, 'P-TAKEN')])))
        ->toThrow(CatalogIntegrityException::class);
    expect(ProductVariation::count())->toBe(0);
});

it('is backed by the database: the partial unique index refuses a duplicate product sku but allows many NULLs', function () {
    catalog()->upsertProduct(product(101, sku: 'HMP-DB'));
    catalog()->upsertProduct(product(102));

    expect(fn () => DB::transaction(fn () => DB::table('products')->insert([
        'woo_product_id' => 999, 'sku' => 'HMP-DB', 'name' => 'x', 'type' => 'simple', 'status' => 'publish', 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(UniqueConstraintViolationException::class);
});

// ============================================= resolveProductBySku (P6 decision)

it('resolves a simple product by its own sku, with a null variation id', function () {
    catalog()->upsertProduct(product(101, sku: 'HMP-RESOLVE'));

    $resolved = catalog()->resolveProductBySku('HMP-RESOLVE');

    expect($resolved->productId)->toBe(Product::sole()->id)->and($resolved->variationId)->toBeNull();
});

it('returns null from resolveProductBySku for an unknown sku', function () {
    expect(catalog()->resolveProductBySku('NOPE'))->toBeNull();
});

it('rolls an existing product back to its previous state when a later step fails', function () {
    catalog()->upsertCategories([category(31), category(32)]);
    catalog()->upsertProduct(product(100, variations: [variation(1001, 'SYN-TAKEN')]));
    catalog()->upsertProduct(product(103, 'قبلی', categories: [31], variations: [variation(1031, 'SYN-ONE')]));

    expect(fn () => catalog()->upsertProduct(product(103, 'بعدی', categories: [32], variations: [variation(1031, 'SYN-TWO'), variation(1032, 'SYN-TAKEN')])))
        ->toThrow(CatalogIntegrityException::class);

    $product = Product::where('woo_product_id', 103)->sole();
    expect($product->name)->toBe('قبلی')
        ->and(linkedCategories($product))->toBe([31])
        ->and(ProductVariation::where('woo_variation_id', 1031)->sole()->sku)->toBe('SYN-ONE')
        ->and(ProductVariation::where('woo_variation_id', 1032)->exists())->toBeFalse();
});
