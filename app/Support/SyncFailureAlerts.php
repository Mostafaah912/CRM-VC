<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\Core\Enums\AlertKind;
use App\Modules\Core\Services\AlertService;
use App\Modules\Sync\Events\CatalogSyncFailed;

/** Turns CatalogSyncFailed into a SyncFailure alert (same pattern as AiBudgetAlerts: AlertKind is a Core enum, so Sync raises an Event). Own dedupe window, so it never hides the orders-sync alert. */
final class SyncFailureAlerts
{
    public function __construct(private readonly AlertService $alerts) {}

    public function onCatalogFailed(CatalogSyncFailed $event): void
    {
        $this->alerts->critical(AlertKind::SyncFailure, 'The catalog sync failed in the nightly chain; the chain continued without it ('.$event->exception.')', ['entity' => 'catalog', 'exception' => $event->exception], dedupeScope: 'catalog');
    }
}
