<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Models\IdentityConflict;
use App\Modules\Customers\Support\IdentityConflictRow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @phpstan-import-type IdentityConflictShape from IdentityConflictRow
 *
 * P2-12, read-only: the identity-conflict list for human review — newest first, 25 to a page, from the conflict rows alone. Resolving a conflict is not here.
 */
final class IdentityConflictService
{
    private const PER_PAGE = 25;

    /** @return LengthAwarePaginator<int, IdentityConflictShape> */
    public function paginate(): LengthAwarePaginator
    {
        return IdentityConflict::query()
            ->select(IdentityConflictRow::COLUMNS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->through(fn (IdentityConflict $conflict): array => IdentityConflictRow::fromModel($conflict)->toArray());
    }
}
