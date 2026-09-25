<?php

declare(strict_types=1);

namespace App\Modules\Segments\Jobs;

use App\Modules\Segments\Services\SegmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * P5-06: measured on dev data (19,586 customers, ARCHITECTURE.md), SegmentService::evaluate() takes
 * ~2.2-2.5s for the broadest possible rule (matching every customer) and ~250-300ms for a typically
 * selective one — right at/over CLAUDE.md §1's "anything over ~2 seconds is a queued Job" line, and
 * rule selectivity is data-dependent and not knowable ahead of time. So the manually-triggered
 * "evaluate" button always queues this rather than ever risking an inline ~2.5s request.
 *
 * A thin wrapper only (CLAUDE.md §1/§5: "a Job resolves a Service and calls it") — the lookup-by-id
 * lives in SegmentService::evaluateById(), never here, since Jobs may not import a module Model
 * (tests/Arch/ArchitectureTest.php, "keeps jobs free of business logic").
 *
 * `tries = 1`: evaluate() already wraps its DELETE+INSERT swap in one transaction, so a failure rolls
 * back to the previous, still-consistent membership — a silent retry could otherwise loop on a
 * genuinely broken rule. `ShouldBeUnique` per segment id: double-clicking "evaluate" queues one run,
 * never two racing DELETE+INSERTs on the same segment_members rows.
 */
final class EvaluateSegmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $segmentId) {}

    public function uniqueId(): string
    {
        return (string) $this->segmentId;
    }

    public function handle(SegmentService $segments): void
    {
        $segments->evaluateById($this->segmentId);
    }
}
