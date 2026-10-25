<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

use App\Modules\Sync\Enums\CatalogSyncMode;
use App\Modules\Sync\Enums\CatalogSyncPhase;
use Carbon\CarbonImmutable;

/**
 * One catalog run as the checkpoint remembers it. `cursorTo` is frozen when the run first starts (the next incremental run reads from
 * it, minus the overlap) and `modifiedAfter` is the lower bound this run reads with — both survive a resume unchanged, so a run that was
 * killed continues over the SAME window instead of drifting. `nextPage` is the first products page NOT yet fully written.
 */
final readonly class CatalogSyncRun
{
    public function __construct(
        public CatalogSyncMode $mode,
        public CarbonImmutable $cursorTo,
        public ?CarbonImmutable $modifiedAfter,
        public CatalogSyncPhase $phase,
        public int $nextPage,
        public CarbonImmutable $startedAt,
        public bool $resumed = false,
    ) {}

    public function at(CatalogSyncPhase $phase, int $nextPage): self
    {
        return new self($this->mode, $this->cursorTo, $this->modifiedAfter, $phase, $nextPage, $this->startedAt, $this->resumed);
    }
}
