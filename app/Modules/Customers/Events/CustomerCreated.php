<?php

declare(strict_types=1);

namespace App\Modules\Customers\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A customer row was created by identity resolution (PRD §07). Ids only — no name, no phone. */
final class CustomerCreated
{
    use Dispatchable;

    public function __construct(public readonly int $customerId) {}
}
