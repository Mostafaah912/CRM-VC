<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

/** What one OrderItemBackfillService::resolveUnresolved() run found (P6 decision, ARCHITECTURE.md). */
final readonly class OrderItemBackfillResult
{
    public function __construct(
        public int $resolvedAsVariation,
        public int $resolvedAsProduct,
        public int $stillUnresolved,
    ) {}
}
