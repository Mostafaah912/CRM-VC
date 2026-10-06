<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Models\IdentityConflict;
use App\Modules\Customers\Support\IdentityConflictRow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @phpstan-import-type IdentityConflictShape from IdentityConflictRow
 *
 * P2-12, read-only: the identity-conflict list for human review — newest first, 25 to a page, from the conflict rows plus
 * (P6-13) the customer they point at, loaded for exactly `id, phone_normalized` and nothing else. Writing a resolution is
 * deliberately NOT here — `tests/Arch/SystemPagesBoundaryTest.php` locks this whole page read-only end to end; the write
 * path for P6-13's re-resolution lives in the separate `IdentityConflictReresolveService`, reachable only from `hm:identity-reresolve`, never from a GET route.
 */
final class IdentityConflictService
{
    private const PER_PAGE = 25;

    /** @return LengthAwarePaginator<int, IdentityConflictShape> */
    public function paginate(): LengthAwarePaginator
    {
        return IdentityConflict::query()
            ->select(IdentityConflictRow::COLUMNS)
            ->with(['customer' => fn ($query) => $query->select(['id', 'phone_normalized'])])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->through(fn (IdentityConflict $conflict): array => IdentityConflictRow::fromModel($conflict)->toArray());
    }
}
