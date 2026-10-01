<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Customers\Services\IdentityConflictReresolveService;
use App\Modules\Orders\Services\OrderService;
use Illuminate\Console\Command;

/**
 * `hm:identity-reresolve` (P6-13): re-resolves, entirely from locally-stored data, the two groups the task
 * named — orders with no customer_id and pending identity_conflicts — against the CURRENT identity rules.
 *
 *  - Orders with no customer_id (`no_phone`, PRD §08 step 1): their raw phone was deliberately never stored
 *    (identity_conflicts carries no phone for this reason), so retrying them needs a fresh Woo read per
 *    order, which this command does not do — it only reports the count. The most likely explanation found
 *    during diagnosis (a billing.phone empty / shipping.phone present order, already seen once for real in
 *    P2-13) would change WHICH field decides identity, so it needs the user's own decision first; not
 *    implemented here (CLAUDE.md §5/the task's own instruction on this exact kind of change).
 *  - Pending `last_name_mismatch` conflicts: closed as confirmed_same only where PersonNameNormalizer's
 *    CURRENT rule, reconstructed from stored data, now says the names match (IdentityConflictReresolveService).
 *    That rule did not change in this task (see docs/architecture/sprint-6.md), so today's run is expected to
 *    close 0 — this command exists so the day a rule does change, re-applying it is one command, not new code.
 */
final class IdentityReresolveCommand extends Command
{
    protected $signature = 'hm:identity-reresolve';

    protected $description = 'Re-resolve orders with no customer and pending identity conflicts against the current rules (no Woo calls)';

    public function handle(IdentityConflictReresolveService $conflicts, OrderService $orders): int
    {
        $needsPhoneReview = $orders->countNeedingPhoneReview();
        $this->line("orders with no customer (needs_phone_review): {$needsPhoneReview} — not actionable here, see class docblock");

        $result = $conflicts->reresolvePendingByCurrentRules();
        $this->line("pending last_name_mismatch examined: {$result->examined}, closed as confirmed_same: {$result->closedAsSame}, still pending: {$result->stillPending}");

        return self::SUCCESS;
    }
}
