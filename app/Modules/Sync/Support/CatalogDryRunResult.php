<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

/**
 * What a read-only pass over the real catalog found, before ever writing anything (P6 decision,
 * ARCHITECTURE.md): how many products/variations were read and how many mapped cleanly, and every
 * distinct mapping-failure reason seen, with its count and one sample ("id=<woo id> — <message>").
 */
final readonly class CatalogDryRunResult
{
    /** @param  array<string, array{count: int, sample: string}>  $errorClasses */
    public function __construct(
        public int $productsScanned,
        public int $productsValid,
        public int $variationsScanned,
        public int $variationsValid,
        public array $errorClasses,
    ) {}
}
