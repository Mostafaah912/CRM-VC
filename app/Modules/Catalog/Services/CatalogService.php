<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Events\ProductSynced;
use App\Modules\Catalog\Exceptions\CatalogIntegrityException;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Models\ProductVariation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Catalog's public write API for Woo sync (PRD §07). Woo is the source of truth and this is its mirror:
 *
 *  - identity is always the woo_*_id (each is UNIQUE); a repeat of the same input changes nothing;
 *  - nothing is ever deleted — a category, product or variation Woo stops listing stays as it is
 *    (order items and manual product costs may point at it);
 *  - a product's category links ARE replaced by the set Woo reports (the pivot holds no other data);
 *  - a category or link whose target is not synced yet is skipped and logged, never invented;
 *  - a SKU another variation owns, or a Woo variation that already lives under a different product,
 *    is refused with CatalogIntegrityException and nothing is written — the spec defines no policy for
 *    either, so none is invented.
 *
 * Units of work: one category batch (two passes, so parent order does not matter) and one product
 * (row + category links + all its variations) are each ONE transaction.
 */
final class CatalogService
{
    /**
     * @param  list<CategoryInput>  $categories
     */
    public function upsertCategories(array $categories): void
    {
        DB::transaction(function () use ($categories): void {
            $rows = [];

            foreach ($categories as $input) {
                $category = ProductCategory::query()->where('woo_category_id', $input->wooCategoryId)->lockForUpdate()->first()
                    ?? new ProductCategory(['woo_category_id' => $input->wooCategoryId]);
                $category->fill(['name' => $input->name, 'slug' => $input->slug]);
                $category->save();
                $rows[$input->wooCategoryId] = [$category, $input->parentWooCategoryId];
            }

            $parentWooIds = array_values(array_filter(array_map(fn (array $row) => $row[1], $rows)));
            $known = $parentWooIds === [] ? [] : ProductCategory::query()->whereIn('woo_category_id', $parentWooIds)->pluck('id', 'woo_category_id')->all();

            foreach ($rows as $wooId => [$category, $parentWooId]) {
                $parentId = $parentWooId === null ? null : ($known[$parentWooId] ?? null);

                if ($parentWooId !== null && $parentId === null) {
                    Log::warning('Catalog category parent is not synced; leaving it without a parent', [
                        'woo_category_id' => $wooId,
                        'parent_woo_category_id' => $parentWooId,
                    ]);
                }

                $category->parent_id = $parentId;
                $category->save();
            }
        });
    }

    /**
     * @return int the local product id
     *
     * @throws CatalogIntegrityException
     */
    public function upsertProduct(ProductInput $input): int
    {
        return DB::transaction(function () use ($input): int {
            $product = Product::query()->where('woo_product_id', $input->wooProductId)->lockForUpdate()->first()
                ?? new Product(['woo_product_id' => $input->wooProductId]);

            $attributes = [
                'name' => $input->name,
                'slug' => $input->slug,
                'type' => $this->productType($input),
                'status' => $this->status($input->status, 'product', $input->wooProductId),
                'synced_at' => now(),
            ];

            // Woo's created date is Woo's: a payload without one never erases what we already have.
            if ($input->createdAtWoo !== null) {
                $attributes['created_at_woo'] = $input->createdAtWoo;
            }

            $product->fill($attributes);
            $product->save();

            $this->syncCategoryLinks($product, $input);
            $this->syncVariations($product, $input);

            $productId = $product->id;
            DB::afterCommit(fn () => ProductSynced::dispatch($productId, $input->wooProductId));

            return $productId;
        });
    }

    private function syncCategoryLinks(Product $product, ProductInput $input): void
    {
        $wanted = array_values(array_unique($input->wooCategoryIds));
        $ids = $wanted === [] ? [] : ProductCategory::query()->whereIn('woo_category_id', $wanted)->pluck('id', 'woo_category_id')->all();
        $missing = array_values(array_diff($wanted, array_keys($ids)));

        if ($missing !== []) {
            Log::warning('Catalog product references categories that are not synced; skipping those links', [
                'woo_product_id' => $input->wooProductId,
                'missing_woo_category_ids' => $missing,
            ]);
        }

        $product->categories()->sync(array_values($ids));
    }

    private function syncVariations(Product $product, ProductInput $input): void
    {
        foreach ($input->variations as $variationInput) {
            $variation = ProductVariation::query()->where('woo_variation_id', $variationInput->wooVariationId)->lockForUpdate()->first();

            if ($variation !== null && $variation->product_id !== $product->id) {
                throw CatalogIntegrityException::variationUnderAnotherProduct($variationInput->wooVariationId, $input->wooProductId);
            }

            $this->assertSkuFree($variation, $variationInput);

            $variation ??= new ProductVariation(['product_id' => $product->id, 'woo_variation_id' => $variationInput->wooVariationId]);
            $variation->fill([
                'sku' => $variationInput->sku,
                'price' => $variationInput->price,
                'status' => $this->status($variationInput->status, 'variation', $variationInput->wooVariationId),
                'attributes' => $variationInput->attributes,
                'synced_at' => now(),
            ]);
            $variation->save();
        }
    }

    private function assertSkuFree(?ProductVariation $variation, VariationInput $input): void
    {
        if ($input->sku === null) {
            return;
        }

        $owner = ProductVariation::query()
            ->where('sku', $input->sku)
            ->when($variation !== null, fn ($query) => $query->where('id', '!=', $variation?->id))
            ->first();

        if ($owner !== null) {
            throw CatalogIntegrityException::skuAlreadyOwned($input->wooVariationId, $input->sku, $owner->id);
        }
    }

    /** Outside Catalog's core set the product is kept, not rejected: 'simple' is the column's own default. */
    private function productType(ProductInput $input): ProductType
    {
        $type = ProductType::tryFrom($input->type);

        if ($type === null) {
            $type = ProductType::Simple;
            Log::warning('Catalog product type is outside the core set; storing it as the default', [
                'woo_product_id' => $input->wooProductId,
                'woo_type' => $input->type,
                'stored_as' => $type->value,
            ]);
        }

        return $type;
    }

    /** Outside the core set (future, trash, auto-draft…) the entity is kept as a non-live draft, not rejected. */
    private function status(string $wooStatus, string $entity, int $wooId): ProductStatus
    {
        $status = ProductStatus::tryFrom($wooStatus);

        if ($status === null) {
            $status = ProductStatus::Draft;
            Log::warning('Catalog status is outside the core set; storing it as a draft', [
                'entity' => $entity,
                'woo_id' => $wooId,
                'woo_status' => $wooStatus,
                'stored_as' => $status->value,
            ]);
        }

        return $status;
    }
}
