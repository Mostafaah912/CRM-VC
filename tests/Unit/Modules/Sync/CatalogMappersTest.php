<?php

declare(strict_types=1);

use App\Modules\Sync\DTOs\CategoryDto;
use App\Modules\Sync\DTOs\ProductDto;
use App\Modules\Sync\DTOs\VariationDto;
use App\Modules\Sync\Exceptions\WooMappingException;
use App\Modules\Sync\Mappers\CategoryMapper;
use App\Modules\Sync\Mappers\ProductMapper;
use App\Modules\Sync\Mappers\VariationMapper;
use Tests\Support\WooPayloads;

/*
| Raw Woo category / product / variation payloads -> DTOs (P2-02 synthetic fixtures).
| Woo's own vocabulary (product type, status) stays a string: Sync may not import the Catalog
| module's Enums (module boundary), and a plugin-defined value must be carried, not rejected —
| mapping it onto Catalog's Enums is P2-05's job.
*/

function failingField(callable $map): string
{
    try {
        $map();
    } catch (WooMappingException $e) {
        return $e->field;
    }

    test()->fail('Expected WooMappingException');
}

// -------------------------------------------------------------- category

it('maps a category, with a Woo parent of 0 meaning none', function () {
    [$root, $child] = array_map(fn (array $raw) => (new CategoryMapper)->map($raw), WooPayloads::items('products/categories'));

    expect($root)->toBeInstanceOf(CategoryDto::class)
        ->and([$root->wooCategoryId, $root->name, $root->slug, $root->parentWooCategoryId])->toBe([31, 'پوشاک آزمایشی', 'synthetic-apparel', null])
        ->and([$child->wooCategoryId, $child->parentWooCategoryId])->toBe([32, 31]);
});

it('reads an empty category slug as none', function () {
    $dto = (new CategoryMapper)->map(WooPayloads::set(WooPayloads::first('products/categories'), 'slug', ''));

    expect($dto->slug)->toBeNull();
});

it('fails on a category that is missing or has a malformed required field', function (string $path, mixed $value, bool $remove) {
    $raw = WooPayloads::first('products/categories');
    $raw = $remove ? WooPayloads::without($raw, $path) : WooPayloads::set($raw, $path, $value);

    expect(failingField(fn () => (new CategoryMapper)->map($raw)))->toBe($path);
})->with([
    'no id' => ['id', null, true],
    'id zero' => ['id', 0, false],
    'no name' => ['name', null, true],
    'blank name' => ['name', '', false],
    'parent as string' => ['parent', '31', false],
    'negative parent' => ['parent', -1, false],
]);

// --------------------------------------------------------------- product

it('maps a simple product, its category ids and its optional fields', function () {
    $dto = (new ProductMapper)->map(WooPayloads::items('products')[0]);

    expect($dto)->toBeInstanceOf(ProductDto::class)
        ->and($dto->wooProductId)->toBe(101)
        ->and($dto->name)->toBe('تی‌شرت آزمایشی')
        ->and($dto->slug)->toBe('synthetic-tee')
        ->and($dto->type)->toBe('simple')
        ->and($dto->status)->toBe('publish')
        ->and($dto->sku)->toBe('SYN-TEE-001')
        ->and($dto->price)->toBe(403880)
        ->and($dto->createdAtWoo?->format('Y-m-d H:i:s'))->toBe('2026-01-05 06:00:00')
        ->and($dto->wooCategoryIds)->toBe([31]);
});

it('maps the variable product too', function () {
    $dto = (new ProductMapper)->map(WooPayloads::items('products')[1]);

    expect([$dto->wooProductId, $dto->type, $dto->wooCategoryIds])->toBe([102, 'variable', [32]]);
});

it('carries a product type or status outside the core set instead of rejecting the product', function (string $type, string $status) {
    $raw = WooPayloads::set(WooPayloads::set(WooPayloads::items('products')[0], 'type', $type), 'status', $status);

    $dto = (new ProductMapper)->map($raw);

    expect([$dto->type, $dto->status])->toBe([$type, $status]);
})->with([['subscription', 'future'], ['variable-subscription', 'trash'], ['bundle', 'publish']]);

it('reads a missing price, sku, slug, date and an empty price as absent', function () {
    $raw = WooPayloads::set(WooPayloads::items('products')[0], 'price', '');
    foreach (['sku', 'slug', 'date_created_gmt'] as $key) {
        $raw = WooPayloads::without($raw, $key);
    }

    $dto = (new ProductMapper)->map($raw);

    expect([$dto->price, $dto->sku, $dto->slug, $dto->createdAtWoo])->toBe([null, null, null, null]);
});

it('maps a product with no categories', function () {
    expect((new ProductMapper)->map(WooPayloads::set(WooPayloads::items('products')[0], 'categories', []))->wooCategoryIds)->toBe([]);
});

it('fails on a product that is missing or has a malformed required field', function (string $path, mixed $value, bool $remove) {
    $raw = WooPayloads::items('products')[0];
    $raw = $remove ? WooPayloads::without($raw, $path) : WooPayloads::set($raw, $path, $value);

    expect(failingField(fn () => (new ProductMapper)->map($raw)))->toBe($path);
})->with([
    'no id' => ['id', null, true],
    'no name' => ['name', null, true],
    'no type' => ['type', null, true],
    'blank type' => ['type', '', false],
    'no status' => ['status', null, true],
    'int status' => ['status', 1, false],
    'no categories key' => ['categories', null, true],
    'categories not a list' => ['categories', 'x', false],
    'category without id' => ['categories.0.id', null, true],
    'category id as string' => ['categories.0.id', '31', false],
    'price with decimals' => ['price', '10.5', false],
    'malformed created date' => ['date_created_gmt', '2026-01-05', false],
]);

// ------------------------------------------------------------- variation

it('maps a variation and its attributes; the parent product comes from the endpoint, not the payload', function () {
    $dto = (new VariationMapper)->map(WooPayloads::items('products/103/variations')[0], wooProductId: 103);

    expect($dto)->toBeInstanceOf(VariationDto::class)
        ->and($dto->wooVariationId)->toBe(1031)
        ->and($dto->wooProductId)->toBe(103)
        ->and($dto->sku)->toBe('SYN-SHIRT-103-S')
        ->and($dto->price)->toBe(250000)
        ->and($dto->status)->toBe('publish')
        ->and($dto->attributes)->toBe(['سایز' => 'S', 'رنگ' => 'مشکی']);
});

it('reads a variation with no sku, no price and no attributes as absent, not as fabricated values', function () {
    $dto = (new VariationMapper)->map(WooPayloads::items('products/103/variations')[1], wooProductId: 103);

    expect([$dto->wooVariationId, $dto->sku, $dto->price, $dto->status, $dto->attributes])->toBe([1032, null, null, 'private', []]);
});

it('refuses a variation whose attribute name repeats rather than silently keep one', function () {
    $raw = WooPayloads::items('products/103/variations')[0];
    $raw['attributes'][] = ['id' => 9, 'name' => 'سایز', 'option' => 'M'];

    expect(failingField(fn () => (new VariationMapper)->map($raw, 103)))->toBe('attributes.2.name');
});

it('fails on a variation that is missing or has a malformed required field', function (string $path, mixed $value, bool $remove) {
    $raw = WooPayloads::items('products/103/variations')[0];
    $raw = $remove ? WooPayloads::without($raw, $path) : WooPayloads::set($raw, $path, $value);

    expect(failingField(fn () => (new VariationMapper)->map($raw, 103)))->toBe($path);
})->with([
    'no id' => ['id', null, true],
    'no status' => ['status', null, true],
    'no attributes key' => ['attributes', null, true],
    'attribute without name' => ['attributes.0.name', null, true],
    'attribute option not a string' => ['attributes.0.option', 5, false],
    'price with decimals' => ['price', '9.5', false],
    'sku not a string' => ['sku', 7, false],
]);

it('rejects a parent product id that is not a real id — that is a caller bug, not payload data', function (int $productId) {
    (new VariationMapper)->map(WooPayloads::items('products/103/variations')[0], $productId);
})->with([[0], [-3]])->throws(InvalidArgumentException::class);
