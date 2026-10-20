<?php

declare(strict_types=1);

namespace App\Modules\Sync\Events;

/** The catalog mirror failed inside the nightly chain and the chain carried on without it. Class name only: a Woo/DB message can carry URLs or data. */
final readonly class CatalogSyncFailed
{
    public function __construct(public string $exception) {}
}
