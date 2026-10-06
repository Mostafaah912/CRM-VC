<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

/**
 * What CatalogService::tryUpsertProduct() found (P6 decision, ARCHITECTURE.md): a SKU conflict is an
 * expected, recoverable outcome for a caller outside Catalog — not something it should have to catch
 * CatalogIntegrityException to learn about, since the module boundary (CLAUDE.md §1) only lets another
 * module reach Catalog through its Service, never its Exceptions. upsertProduct() itself is unchanged
 * and still throws for callers inside Catalog that want the loud, transactional contract.
 */
final readonly class CatalogUpsertOutcome
{
    private function __construct(
        public bool $accepted,
        public ?int $productId,
        public ?string $rejectionReason,
    ) {}

    public static function accepted(int $productId): self
    {
        return new self(true, $productId, null);
    }

    public static function rejected(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
