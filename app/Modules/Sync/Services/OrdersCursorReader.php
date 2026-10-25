<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Models\SyncCursor;
use Carbon\CarbonImmutable;

/**
 * Read-only: where the orders poll has got to (the stored orders cursor). Exists so ReconciliationService can bound the running month
 * without naming the run models — only SyncService writes them (SyncRunBoundaryTest), and this class never writes.
 */
final class OrdersCursorReader
{
    public function value(): ?CarbonImmutable
    {
        return SyncCursor::query()->find(SyncEntity::Orders->value)?->cursor_value;
    }
}
