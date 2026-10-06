<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Modules\Sync\Exceptions\WooMappingException;
use App\Modules\Sync\Mappers\ProductMapper;
use App\Modules\Sync\Mappers\VariationMapper;
use App\Modules\Sync\Support\CatalogDryRunResult;

/**
 * Read-only, write-nothing pass over every real Woo product and variation (P6 decision,
 * ARCHITECTURE.md — the check before resuming CatalogSyncJob's live run): only the P2-03 mappers touch
 * the payload, so a malformed one is counted and never propagates. No CatalogService dependency exists
 * here at all, so this class cannot write to the catalog even by accident. WooClient::pages() is a
 * generator (HttpWooClient P2-02): one page of one endpoint is ever held in memory, so this stays bounded
 * over the whole catalog, unlike the ad-hoc scan that had to be aborted (ARCHITECTURE.md Open Item).
 * Categories are out of scope: decision 3 asks only about products and variations.
 */
final class CatalogDryRunService
{
    private const VARIABLE_TYPE = 'variable';

    public function __construct(
        private readonly WooClient $woo,
        private readonly ProductMapper $products = new ProductMapper,
        private readonly VariationMapper $variations = new VariationMapper,
    ) {}

    public function scan(): CatalogDryRunResult
    {
        $productsScanned = 0;
        $productsValid = 0;
        $variationsScanned = 0;
        $variationsValid = 0;
        $errors = [];

        foreach ($this->woo->pages('products') as $page) {
            foreach ($page->items as $raw) {
                $productsScanned++;

                try {
                    $dto = $this->products->map($raw);
                    $productsValid++;
                } catch (WooMappingException $e) {
                    $this->record($errors, $e, $raw['id'] ?? null);

                    continue;
                }

                if ($dto->type !== self::VARIABLE_TYPE) {
                    continue;
                }

                foreach ($this->woo->pages("products/{$dto->wooProductId}/variations") as $vpage) {
                    foreach ($vpage->items as $vraw) {
                        $variationsScanned++;

                        try {
                            $this->variations->map($vraw, $dto->wooProductId);
                            $variationsValid++;
                        } catch (WooMappingException $e) {
                            $this->record($errors, $e, $vraw['id'] ?? null);
                        }
                    }
                }
            }
        }

        return new CatalogDryRunResult($productsScanned, $productsValid, $variationsScanned, $variationsValid, $errors);
    }

    /** @param  array<string, array{count: int, sample: string}>  $errors */
    private function record(array &$errors, WooMappingException $e, mixed $wooId): void
    {
        $reason = "{$e->field}: {$e->getMessage()}";

        if (! isset($errors[$reason])) {
            $errors[$reason] = ['count' => 0, 'sample' => "id={$wooId} — {$e->getMessage()}"];
        }

        $errors[$reason]['count']++;
    }
}
