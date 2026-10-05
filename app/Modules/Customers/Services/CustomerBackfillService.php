<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Enums\IdentityConflictStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\IdentityConflict;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Two one-time, local-only backfills (P6-14 phase 4) — no Woo call, safe to re-run any number of
 * times: `first_seen_at` from `orders` already synced locally, and `needs_review` from pending
 * `identity_conflicts` that predate the fix making `CustomerIdentityService` write it. Province/city
 * are NOT here: that data was never stored locally at all (only on Woo), so it needs a live sync —
 * `hm:sync --full`, already updated to capture it, not a second backfill mechanism.
 *
 * No raw SQL (`app/Modules/Customers/**` is not one of the Rule-7 exceptions — unlike Metrics/
 * Analytics, CLAUDE.md §3 bans it outright here): the per-customer minimum is computed with one
 * ordered, lazily-chunked pass over `orders` in PHP, then written back with a plain `where('id',
 * ...)->update()` per customer that actually needs to move. `Customer::upsert()` was tried first and
 * rejected: Postgres's `INSERT ... ON CONFLICT DO UPDATE` still builds and NOT-NULL-checks the full
 * candidate row before it ever resolves the conflict, so a partial column list (no `phone_normalized`,
 * which this backfill has no business touching) fails even on the update path, not just on insert.
 */
final class CustomerBackfillService
{
    /** @return int customers whose first_seen_at moved earlier */
    public function backfillFirstSeenAtFromLocalOrders(): int
    {
        $minByCustomer = $this->minOrderedAtByCustomer();

        if ($minByCustomer === []) {
            return 0;
        }

        $existing = Customer::query()
            ->whereIn('id', array_keys($minByCustomer))
            ->pluck('first_seen_at', 'id');

        $now = now();
        $moved = 0;

        foreach ($minByCustomer as $customerId => $minOrderedAt) {
            $current = $existing[$customerId] ?? null;

            if ($current !== null && CarbonImmutable::parse($current)->lessThanOrEqualTo($minOrderedAt)) {
                continue;
            }

            Customer::query()->where('id', $customerId)->update(['first_seen_at' => $minOrderedAt, 'updated_at' => $now]);
            $moved++;
        }

        return $moved;
    }

    /** @return int customers newly marked needing review */
    public function backfillNeedsReviewFromPendingConflicts(): int
    {
        $customerIds = IdentityConflict::query()
            ->where('status', IdentityConflictStatus::Pending)
            ->whereNotNull('customer_id')
            ->distinct()
            ->pluck('customer_id');

        if ($customerIds->isEmpty()) {
            return 0;
        }

        return Customer::query()
            ->whereIn('id', $customerIds)
            ->where('needs_review', false)
            ->update(['needs_review' => true]);
    }

    /**
     * One ordered, lazily-chunked pass: since the query is sorted (customer_id, ordered_at) both
     * ascending, the FIRST row read for each customer_id is already its minimum — no in-PHP
     * comparison needed, no aggregate SQL function.
     *
     * @return array<int, string>
     */
    private function minOrderedAtByCustomer(): array
    {
        $min = [];

        DB::table('orders')
            ->whereNotNull('customer_id')
            ->whereNull('deleted_at')
            ->orderBy('customer_id')
            ->orderBy('ordered_at')
            ->select(['customer_id', 'ordered_at'])
            ->lazy(1000)
            ->each(function (object $row) use (&$min): void {
                if (! array_key_exists($row->customer_id, $min)) {
                    $min[$row->customer_id] = $row->ordered_at;
                }
            });

        return $min;
    }
}
