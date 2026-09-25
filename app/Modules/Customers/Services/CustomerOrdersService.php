<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CursorPage;
use App\Modules\Customers\Support\PageCursor;
use App\Modules\Customers\Support\ResolvesCursorPages;
use App\Support\TehranDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The Orders tab of Customer 360 (P3-05): ALL of one customer's orders, newest first, paged by a cursor over (ordered_at DESC, id DESC).
 * Read-only, and a documented data dependency (ARCHITECTURE.md, "Data dependency در برابر Module dependency"): it reads the `orders`
 * table with the query builder and never `use`s a class of the Orders module.
 *
 * Money leaves as int Toman, exactly as stored — the store's unit is Toman already (P0-00), so there is nothing to convert.
 * A soft-deleted or missing customer is a 404. total_count is its own COUNT(*), so it is exact however many pages there are.
 *
 * @phpstan-type OrderRow array{woo_order_id: int, status: string, total: int, is_realized: bool, ordered_at_jalali: string, ordered_at_iso: string}
 */
class CustomerOrdersService
{
    use ResolvesCursorPages;

    /** @return CursorPage<OrderRow> */
    public function pageFor(int $customerId, ?string $cursor = null, ?int $perPage = null): CursorPage
    {
        $customer = Customer::query()->select('id')->findOrFail($customerId);
        $limit = $this->limit($perPage);

        $query = DB::table('orders')
            ->where('customer_id', $customer->id)
            ->whereNull('deleted_at')
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->limit($limit + 1);

        if ($cursor !== null) {
            $position = $this->position($cursor, ['ordered_at' => 'instant', 'id' => 'id']);

            $query->whereRowValues(['ordered_at', 'id'], '<', [$position->instant('ordered_at')->format('Y-m-d H:i:sP'), $position->id('id')]);
        }

        $rows = $query->get(['id', 'woo_order_id', 'status', 'total', 'ordered_at', 'is_realized']);
        $hasMore = $rows->count() > $limit;
        $shown = $rows->take($limit)->values();
        $last = $shown->last();

        $orders = [];

        foreach ($shown as $row) {
            $at = CarbonImmutable::parse((string) $row->ordered_at, 'UTC');

            $orders[] = [
                'woo_order_id' => (int) $row->woo_order_id,
                'status' => (string) $row->status,
                'total' => (int) $row->total,
                'is_realized' => (bool) $row->is_realized,
                'ordered_at_jalali' => TehranDateTime::format($at),
                'ordered_at_iso' => $at->utc()->toIso8601ZuluString(),
            ];
        }

        $next = $hasMore && $last !== null
            ? PageCursor::encode(['ordered_at' => CarbonImmutable::parse((string) $last->ordered_at, 'UTC'), 'id' => (int) $last->id])
            : null;

        $total = DB::table('orders')->where('customer_id', $customer->id)->whereNull('deleted_at')->count();

        return new CursorPage($orders, $next, $total);
    }
}
