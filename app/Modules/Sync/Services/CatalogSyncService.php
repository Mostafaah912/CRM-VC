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
use App\Modules\Sync\Enums\CatalogSyncPhase;
use App\Modules\Sync\Exceptions\WooMappingException;
use App\Modules\Sync\Mappers\CategoryMapper;
use App\Modules\Sync\Mappers\ProductMapper;
use App\Modules\Sync\Mappers\VariationMapper;
use App\Modules\Sync\Support\CatalogSyncResult;
use App\Modules\Sync\Support\CatalogSyncRun;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Woo -> Catalog (P2-05): reads through the WooClient contract, turns raw payloads into DTOs with the
 * P2-03 mappers, and hands typed inputs to Catalog's public service. No HTTP, no models, no database
 * here — and no scheduling, cursors or jobs (P2-08): a caller decides when and in what order to run it.
 *
 * Categories are always read in full (a few small pages, no modified filter). Products are read INCREMENTALLY (P8-03g): only those Woo
 * modified after the last completed run (minus the overlap), ordered by id, with a page checkpoint so a killed or failed run resumes at the
 * page it reached; a FULL rebuild (weekly, a first run, a stale cursor) reads them all. A variation-only change that does not touch its
 * parent's modified date is picked up by the full rebuild, not before. Requests ask only for the fields the mappers read (`_fields`).
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

    /** Exactly the fields the mappers read — Woo otherwise answers every product with ~1.5 MB of descriptions, images, meta and links per page. */
    public const CATEGORY_FIELDS = 'id,name,slug,parent';

    public const PRODUCT_FIELDS = 'id,name,slug,type,status,sku,price,date_created_gmt,categories';

    public const VARIATION_FIELDS = 'id,sku,price,status,attributes';

    public function __construct(
        private readonly WooClient $woo,
        private readonly CatalogService $catalog,
        private readonly CategoryMapper $categories = new CategoryMapper,
        private readonly ProductMapper $products = new ProductMapper,
        private readonly VariationMapper $variations = new VariationMapper,
        private readonly CatalogSyncCheckpoint $checkpoint = new CatalogSyncCheckpoint,
    ) {}

    /**
     * One catalog run — what CatalogSyncJob runs: categories, then products (+ variations), incrementally unless `$full` (or the checkpoint
     * says a full rebuild is due), resuming a checkpointed run when there is one. A failure marks the cursor row failed, keeps the checkpoint
     * and propagates; the job's failed() hook (CatalogSyncCheckpoint::recordFailure) turns it, and an uncatchable timeout kill, into one CatalogSyncFailed alert.
     */
    public function syncAll(bool $full = false): CatalogSyncResult
    {
        $run = $this->checkpoint->begin($full);

        Log::info('Catalog sync started', ['mode' => $run->mode->value, 'resumed' => $run->resumed, 'phase' => $run->phase->value, 'next_page' => $run->nextPage]);

        try {
            if ($run->phase === CatalogSyncPhase::Categories) {
                $this->syncCategories();
                $run = $run->at(CatalogSyncPhase::Products, 1);
                $this->checkpoint->advance($run);
            }

            $result = $this->syncProducts($run);
            $this->checkpoint->complete($run);
        } catch (Throwable $e) {
            $this->checkpoint->fail();

            throw $e;
        }

        Log::info('Catalog sync finished', ['mode' => $run->mode->value, 'products' => $result->products, 'variations' => $result->variations, 'rejected' => $result->rejectedProducts]);

        return $result;
    }

    /**
     * Every category is read and mapped before any is written, so one malformed payload writes nothing
     * and a parent may sit on a later page than its child.
     *
     * @return int categories written
     */
    public function syncCategories(): int
    {
        $dtos = [];

        foreach ($this->woo->pages('products/categories', query: ['_fields' => self::CATEGORY_FIELDS]) as $page) {
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

    /**
     * Products page by page, ordered by id so a page number keeps its meaning across a resume. With a `$run` the reading window (modified_after)
     * and start page come from it and every fully written page is checkpointed; without one it is a plain full read (tests, ad-hoc use).
     */
    public function syncProducts(?CatalogSyncRun $run = null): CatalogSyncResult
    {
        $products = 0;
        $variations = 0;
        $rejected = 0;

        $query = ['orderby' => 'id', 'order' => 'asc', '_fields' => self::PRODUCT_FIELDS];

        if ($run?->modifiedAfter !== null) {
            $query['modified_after'] = $run->modifiedAfter->format('Y-m-d\TH:i:s');
            $query['dates_are_gmt'] = 'true';
        }

        $pageNumber = $run !== null && $run->phase === CatalogSyncPhase::Products ? $run->nextPage : 1;

        do {
            $page = $this->woo->page('products', $pageNumber, null, $query);

            foreach ($page->items as $raw) {
                try {
                    $dto = $this->products->map($raw);
                    $this->logDroppedPrice('product', $dto->wooProductId, $dto->droppedPrice);
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

            $pageNumber++;

            if ($run !== null && $page->hasMore()) {
                $this->checkpoint->advance($run->at(CatalogSyncPhase::Products, $pageNumber));
            }
        } while ($page->hasMore());

        return new CatalogSyncResult($products, $variations, $rejected);
    }

    /** @return list<VariationInput> */
    private function variationsOf(ProductDto $product): array
    {
        $inputs = [];

        foreach ($this->woo->pages("products/{$product->wooProductId}/variations", query: ['_fields' => self::VARIATION_FIELDS]) as $page) {
            foreach ($page->items as $raw) {
                $variation = $this->variations->map($raw, $product->wooProductId);
                $this->logDroppedPrice('variation', $variation->wooVariationId, $variation->droppedPrice, $product->wooProductId);
                $inputs[] = $this->variationInput($variation);
            }
        }

        return $inputs;
    }

    /** A price Woo sent that is not a whole-Toman amount is stored as NULL (never rounded); this is where that is made visible. */
    private function logDroppedPrice(string $entity, int $wooId, ?string $raw, ?int $wooProductId = null): void
    {
        if ($raw === null) {
            return;
        }

        Log::warning('Catalog price is not a whole-Toman amount; stored as NULL', array_filter([
            'entity' => $entity, 'woo_id' => $wooId, 'woo_product_id' => $wooProductId, 'field' => 'price', 'value' => $raw,
        ], fn (mixed $v) => $v !== null));
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
