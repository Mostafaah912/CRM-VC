<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Enums\IdentityConflictStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\IdentityConflict;
use App\Modules\Customers\Support\IdentityConflictReresolveResult;
use Illuminate\Support\Facades\DB;

/**
 * P6-13: re-applies PersonNameNormalizer's CURRENT rule (unchanged by this task — a diagnosis of the real
 * pending conflicts on dev found no safe, unambiguous normalizer gap to fix; see docs/architecture/sprint-6.md)
 * to every pending `last_name_mismatch` row, entirely from stored data — no Woo call, safe to run any number
 * of times. Deliberately separate from the read-only `IdentityConflictService` the GET page uses:
 * `tests/Arch/SystemPagesBoundaryTest.php` locks that one read-only end to end, and this one writes. Only
 * `hm:identity-reresolve` calls this.
 *
 * `incoming_name` is the composed `first + ' ' + last` CustomerIdentityService wrote at detection time; the
 * raw incoming last name alone was never stored. Since normalize() strips the separating space,
 * normalize(incoming_name) is exactly normalize(incoming_first) . normalize(incoming_last) concatenated, so
 * normalize(incoming_last) is necessarily a suffix of it. A conflict closes only when that suffix equals the
 * customer's CURRENT normalized last name and is at least MIN_SUFFIX_LENGTH characters long, so a one-character
 * coincidence can never close something it should not. This reconstructs the exact original comparison — it
 * does not loosen it, and it never touches a genuinely different name.
 */
final class IdentityConflictReresolveService
{
    /** Minimum normalized length a reconstructed suffix match must reach — guards against a 1-character coincidence. */
    private const MIN_SUFFIX_LENGTH = 2;

    public function __construct(private readonly PersonNameNormalizer $names) {}

    public function reresolvePendingByCurrentRules(): IdentityConflictReresolveResult
    {
        $pending = IdentityConflict::query()
            ->where('reason', CustomerIdentityService::REASON_LAST_NAME_MISMATCH)
            ->where('status', IdentityConflictStatus::Pending)
            ->whereNotNull('customer_id')
            ->get(['id', 'customer_id', 'incoming_name']);

        $lastNames = Customer::query()
            ->whereIn('id', $pending->pluck('customer_id')->unique())
            ->pluck('last_name', 'id');

        $closed = 0;

        foreach ($pending as $conflict) {
            if ($this->nowMatches($lastNames[$conflict->customer_id] ?? null, $conflict->incoming_name)) {
                $this->closeAsSame($conflict->id);
                $closed++;
            }
        }

        return new IdentityConflictReresolveResult($pending->count(), $closed, $pending->count() - $closed);
    }

    private function nowMatches(?string $lastName, ?string $incomingName): bool
    {
        if ($lastName === null || $incomingName === null) {
            return false;
        }

        $existing = $this->names->normalize($lastName);
        $incoming = $this->names->normalize($incomingName);

        return $existing !== '' && mb_strlen($existing) >= self::MIN_SUFFIX_LENGTH && str_ends_with($incoming, $existing);
    }

    /** One pending row, closed only if it is still pending (no race with a human reviewer resolving it meanwhile). */
    private function closeAsSame(int $conflictId): void
    {
        DB::table('identity_conflicts')
            ->where('id', $conflictId)
            ->where('status', IdentityConflictStatus::Pending->value)
            ->update(['status' => IdentityConflictStatus::ConfirmedSame->value, 'resolved_at' => now()]);
    }
}
