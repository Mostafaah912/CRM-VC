<?php

declare(strict_types=1);

namespace App\Modules\Sync\DTOs;

/** A Woo product category. `parentWooCategoryId` is null for a root category (Woo sends 0). */
final readonly class CategoryDto
{
    public function __construct(
        public int $wooCategoryId,
        public string $name,
        public ?string $slug,
        public ?int $parentWooCategoryId,
    ) {}
}
