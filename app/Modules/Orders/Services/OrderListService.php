<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Support\OrderListFilters;
use App\Modules\Orders\Support\OrderListRow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * P3-06, read-only: the order list. `orders` LEFT JOIN `customers` (for the display name only — an order without a customer,
 * needs_phone_review, is never dropped by the join). Read with the query builder, never Eloquent's Customer or Order models: the
 * Orders module never `use`s a class of another module (ArchitectureTest); reading another module's TABLE this way is the same
 * documented data dependency P3-03 uses in reverse (Customers reading Orders' tables).
 *
 *  - Filters narrow with AND: an exact woo_order_id, a status (validated against the statuses actually stored, not an enum —
 *    CLAUDE.md forbids hardcoding order statuses, they are store data), is_realized, needs_phone_review (P3-06's own filter —
 *    P3-01's customer list filters only customers.needs_review, a different flag on a different table), and a Jalali day range
 *    on ordered_at ([from, before): inclusive start, exclusive end, same convention as P3-01).
 *  - Order is ordered_at DESC, id DESC: one stable order, so a page never repeats or skips an order.
 *  - Offset pagination, 25 a page — this list expects to be browsed a page at a time by a human, not walked to the end by a
 *    script, so an offset count is appropriate here unlike the cursor lists of Customer 360.
 *
 * @phpstan-import-type OrderListShape from OrderListRow
 */
final class OrderListService
{
    private const PER_PAGE = 25;

    /** @return LengthAwarePaginator<int, OrderListShape> */
    public function paginate(OrderListFilters $filters, int $page = 1, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $this->query($filters)
            ->orderByDesc('orders.ordered_at')
            ->orderByDesc('orders.id')
            ->paginate($perPage, OrderListRow::COLUMNS, 'page', $page)
            ->withQueryString()
            ->through(fn (object $row): array => OrderListRow::fromRow((array) $row)->toArray());
    }

    /**
     * Every status actually stored in `orders.status`, for the filter form — never a hardcoded list.
     *
     * @return list<string>
     */
    public function statusOptions(): array
    {
        return array_values(array_map('strval', DB::table('orders')->distinct()->orderBy('status')->pluck('status')->all()));
    }

    private function query(OrderListFilters $filters): Builder
    {
        return DB::table('orders')
            ->leftJoin('customers', 'customers.id', '=', 'orders.customer_id')
            ->when($filters->wooOrderId !== null, fn (Builder $query) => $query->where('orders.woo_order_id', $filters->wooOrderId))
            ->when($filters->status !== null, fn (Builder $query) => $query->where('orders.status', $filters->status))
            ->when($filters->isRealized !== null, fn (Builder $query) => $query->where('orders.is_realized', $filters->isRealized))
            ->when($filters->needsPhoneReview !== null, fn (Builder $query) => $query->where('orders.needs_phone_review', $filters->needsPhoneReview))
            ->when($filters->orderedFrom !== null, fn (Builder $query) => $query->where('orders.ordered_at', '>=', $filters->orderedFrom))
            ->when($filters->orderedBefore !== null, fn (Builder $query) => $query->where('orders.ordered_at', '<', $filters->orderedBefore));
    }
}
