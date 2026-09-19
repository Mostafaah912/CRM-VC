<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

/**
 * A Woo category as Catalog's public service accepts it. Catalog depends on Core only (PRD §07), so it
 * cannot take Sync's DTOs; the caller copies the fields over. `parentWooCategoryId` is null for a root.
 */
final readonly class CategoryInput
{
    public function __construct(
        public int $wooCategoryId,
        public string $name,
        public ?string $slug,
        public ?int $parentWooCategoryId,
    ) {}
}
