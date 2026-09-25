<?php

declare(strict_types=1);

namespace App\Modules\Customers\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A pending identity_conflicts row was recorded for human review (PRD §07). Ids only — no name, no phone. */
final class IdentityConflictDetected
{
    use Dispatchable;

    public function __construct(
        public readonly int $customerId,
        public readonly int $conflictId,
        public readonly ?int $wooOrderId,
    ) {}
}
