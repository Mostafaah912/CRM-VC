<?php

declare(strict_types=1);

namespace App\Modules\Sync\Exceptions;

use RuntimeException;

/**
 * A chunked run stopped at its page limit but its last order is not far enough past the run's own window start for the
 * next window (last order - overlap) to start later than this one did: continuing would re-read the same orders forever.
 * That means more orders were modified within one overlap than the page limit holds (a bulk import or edit). The run
 * fails, the cursor stays, and the message names the two settings that can fix it — never an order, a time or a phone.
 */
final class SyncStalledException extends RuntimeException
{
    public function __construct(public readonly int $pages)
    {
        parent::__construct("Sync chunk made no progress: the last {$pages} pages ended inside the overlap window, so the next run would start where this one did. Raise woo.sync_max_pages_per_run or lower woo.overlap_minutes.");
    }
}
