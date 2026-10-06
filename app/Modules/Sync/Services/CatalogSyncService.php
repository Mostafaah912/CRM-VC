<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Catalog\Services\CategoryInput;
use App\Modules\Catalog\Services\ProductInput;
use App\Modules\Catalog\Services\VariationInput;
use App\Modules\Sync\DTOs\CategoryDto;
use App\Modules\Sync\DTOs\ProductDto;
use App\Modules\Sync\DTOs\VariationDto;
use App\Modules\Sync\Exceptions\WooMappingException;
use App\Modules\Sync\Mappers\CategoryMapper;
use App\Modules\Sync\Mappers\ProductMapper;
use App\Modules\Sync\Mappers\VariationMapper;
use App\Modules\Sync\Support\CatalogSyncResult;
use Illuminate\Support\Facades\Log;

/**
 * Woo -> Catalog (P2-05): reads through the WooClient contract, turns raw payloads into DTOs with the
 * P2-03 mappers, and hands typed inputs to Catalog's public service. No HTTP, no models, no database
 * here — and no scheduling, cursors or jobs (P2-08): a caller decides when and in what order to run it.
 *
 * Full lists, no window: PRD §10 polls catalog data nightly and Woo's categories have no modified filter.
 * A variable product is written together with its variations (read from its own endpoint FIRST, so a
 * variable product is never stored without them). A malformed category payload still stops the whole
 * categories batch (nothing is written of it — see syncCategories()'s own doc). A malformed PRODUCT
 * payload or a catalog conflict is different (P6 decision, ARCHITECTURE.md): it is logged as a warning
 * with its reason and counted, and the run moves on to the next product — mirroring the existing Orders
 * rule "an unresolvable product never rejects an order" (`.claude/rules/sync.md`). A mapping failure is
 * this module's own WooMappingException, caught directly; a catalog conflict is reported by
 * CatalogService::tryUpsertProduct()'s CatalogUpsertOutcome, never by catching CatalogIntegrityException
 * (the module boundary never lets Sync import Catalog's Exceptions, only its Services). A
 * WooRequestException (a real HTTP/connectivity failure, e.g. 401/5xx reading a product's variations) is
 * not a product-data problem and still stops the run and propagates; what was already committed stays,
 * and the run can simply be repeated.
 */
final class CatalogSyncService
{
    /** Woo's own product type slug for products that have variations. */
    private const VARIABLE_TYPE = 'variable';

    public function __construct(
        private readonly WooClient $woo,
        private readonly CatalogService $catalog,
        private readonly CategoryMapper $categories = new CategoryMapper,
        private readonly ProductMapper $products = new ProductMapper,
        private readonly VariationMapper $variations = new VariationMapper,
    ) {}

    /**
     * Every category is read and mapped before any is written, so one malformed payload writes nothing
     * and a parent may sit on a later page than its child.
     *
     * @return int categories written
     */
    public function syncCategories(): int
    {
        $dtos = [];

        foreach ($this->woo->pages('products/categories') as $page) {
            foreach ($page->items as $raw) {
                $dtos[] = $this->categories->map($raw);
            }
        }

        $this->catalog->upsertCategories(array_map(
            fn (CategoryDto $c) => new CategoryInput($c->wooCategoryId, $c->name, $c->slug, $c->parentWooCategoryId),
            $dtos,
        ));

        return count($dtos);
    }

    public function syncProducts(): CatalogSyncResult
    {
        $products = 0;
        $variations = 0;
        $rejected = 0;

        foreach ($this->woo->pages('products') as $page) {
            foreach ($page->items as $raw) {
                try {
                    $dto = $this->products->map($raw);
                    $variationInputs = $dto->type === self::VARIABLE_TYPE ? $this->variationsOf($dto) : [];
                } catch (WooMappingException $e) {
                    $rejected++;
                    Log::warning('Catalog product rejected during sync; skipping it and continuing', [
                        'woo_product_id' => $raw['id'] ?? null,
                        'reason' => $e->getMessage(),
                    ]);

                    continue;
                }

                $outcome = $this->catalog->tryUpsertProduct($this->input($dto, $variationInputs));

                if (! $outcome->accepted) {
                    $rejected++;
                    Log::warning('Catalog product rejected during sync; skipping it and continuing', [
                        'woo_product_id' => $dto->wooProductId,
                        'reason' => $outcome->rejectionReason,
                    ]);

                    continue;
                }

                $products++;
                $variations += count($variationInputs);
            }
        }

        return new CatalogSyncResult($products, $variations, $rejected);
    }

    /** @return list<VariationInput> */
    private function variationsOf(ProductDto $product): array
    {
        $inputs = [];

        foreach ($this->woo->pages("products/{$product->wooProductId}/variations") as $page) {
            foreach ($page->items as $raw) {
                $inputs[] = $this->variationInput($this->variations->map($raw, $product->wooProductId));
            }
        }

        return $inputs;
    }

    /** @param  list<VariationInput>  $variations */
    private function input(ProductDto $p, array $variations): ProductInput
    {
        return new ProductInput($p->wooProductId, $p->name, $p->slug, $p->type, $p->status, $p->createdAtWoo, $p->wooCategoryIds, $variations, $p->sku, $p->price);
    }

    private function variationInput(VariationDto $v): VariationInput
    {
        return new VariationInput($v->wooVariationId, $v->sku, $v->price, $v->status, $v->attributes);
    }
}
