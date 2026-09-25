<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** What the paged endpoints of Customer 360 share: the page size rule, and a cursor that is not ours being the caller's 422. */
trait ResolvesCursorPages
{
    /** @return int the page size: the caller's, else the default, kept within 1..MAX_PER_PAGE */
    private function limit(?int $perPage): int
    {
        return max(1, min($perPage ?? CursorPage::DEFAULT_PER_PAGE, CursorPage::MAX_PER_PAGE));
    }

    /**
     * @param  array<string, 'instant'|'id'|'text'>  $shape
     *
     * @throws ValidationException a cursor that is not ours is the caller's input error (422), not a server fault
     */
    private function position(string $cursor, array $shape): CursorPosition
    {
        try {
            return PageCursor::decode($cursor, $shape);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['cursor' => 'نشانگر صفحه معتبر نیست.']);
        }
    }
}
