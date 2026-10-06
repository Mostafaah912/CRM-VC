<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Customers\Services\CustomerBackfillService;
use Illuminate\Console\Command;

/**
 * `hm:customers-backfill` (P6-14 phase 4): the two LOCAL-only customer-list gaps — `first_seen_at`
 * (from `orders` already synced) and `needs_review` (from pending `identity_conflicts` that predate
 * `CustomerIdentityService` actually writing the flag). No Woo call, safe to re-run any number of
 * times. Province/city are NOT here — see `CustomerBackfillService`'s docblock; they need
 * `hm:sync --full` instead.
 */
final class CustomerBackfillCommand extends Command
{
    protected $signature = 'hm:customers-backfill';

    protected $description = 'Backfill customers.first_seen_at and needs_review from already-synced local data (no Woo call)';

    public function handle(CustomerBackfillService $backfill): int
    {
        $movedEarlier = $backfill->backfillFirstSeenAtFromLocalOrders();
        $this->line("first_seen_at moved earlier (or filled in) for {$movedEarlier} customers, from local orders only.");

        $flagged = $backfill->backfillNeedsReviewFromPendingConflicts();
        $this->line("needs_review newly set true for {$flagged} customers with a pending identity conflict.");

        $this->line('province/city are not backfilled here — they need a live Woo read. Run hm:sync --full for that.');

        return self::SUCCESS;
    }
}
