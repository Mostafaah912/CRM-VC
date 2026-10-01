<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

/** What one IdentityConflictService::reresolvePendingByCurrentRules() run found (P6-13). */
final readonly class IdentityConflictReresolveResult
{
    public function __construct(
        public int $examined,
        public int $closedAsSame,
        public int $stillPending,
    ) {}
}
