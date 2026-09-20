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
 * The Products tab of Customer 360 (P3-05): every product the customer bought, one row per NAME it was sold under (an item whose
 * product could not be resolved has no product_id, so the snapshot name is the only key that always exists), with the total
 * quantity, the number of orders that held it, and when last. Only realized orders count (orders.is_realized, set from
 * config('woo.realized_statuses')); soft-deleted orders never do. Paged by a cursor over (last_ordered_at DESC, name ASC).
 *
 * One query reads the customer's item lines and they are grouped and paged here: raw SQL (an aggregate GROUP BY) is confined to
 * Metrics/Analytics by ArchitectureTest, and one customer's lines are tens to hundreds of rows, not an analytics scan. Moving
 * this to a SQL aggregate is a one-file change once Rule 7 allows it. Like the orders tab it reads `order_items`/`orders` as a
 * documented data dependency, never a class of the Orders module. The SKU and the last date are the most recent line's.
 *
 * Names are ordered by byte value (strcmp), in the same function that filters by the cursor, so the order and the cursor can
 * never disagree.
 *
 * @phpstan-type ProductRow array{name: string, sku: string|null, total_qty: int, order_count: int, last_ordered_at_jalali: string, last_ordered_at_iso: string}
 */
class CustomerProductsService
{
    use ResolvesCursorPages;

    /** @return CursorPage<ProductRow> */
    public function pageFor(int $customerId, ?string $cursor = null, ?int $perPage = null): CursorPage
    {
        $customer = Customer::query()->select('id')->findOrFail($customerId);
        $limit = $this->limit($perPage);

        $lines = DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.customer_id', $customer->id)
            ->where('o.is_realized', true)
            ->whereNull('o.deleted_at')
            ->orderByDesc('o.ordered_at')
            ->orderByDesc('i.id')
            ->get(['i.name_snapshot', 'i.sku', 'i.qty', 'i.order_id', 'o.ordered_at']);

        $groups = [];

        foreach ($lines as $line) {
            $name = (string) $line->name_snapshot;
            $key = 'n:'.$name; // a prefix, so a numeric-looking name is never turned into an integer key

            // Lines arrive newest first, so the first line of a group is its latest purchase.
            $groups[$key] ??= [
                'name' => $name,
                'sku' => $line->sku === null ? null : (string) $line->sku,
                'qty' => 0,
                'orders' => [],
                'last' => CarbonImmutable::parse((string) $line->ordered_at, 'UTC'),
            ];
            $groups[$key]['qty'] += (int) $line->qty;
            $groups[$key]['orders'][(int) $line->order_id] = true;
        }

        $groups = array_values($groups);
        // Newest last purchase first, then name by byte value. strcmp, never <=>: PHP compares two numeric-looking names ("10", "9")
        // as numbers with <=>, which would disagree with the strcmp the cursor filter below uses.
        usort($groups, function (array $a, array $b): int {
            $byDate = $b['last']->getTimestamp() <=> $a['last']->getTimestamp();

            return $byDate !== 0 ? $byDate : strcmp($a['name'], $b['name']);
        });

        if ($cursor !== null) {
            $position = $this->position($cursor, ['last_ordered_at' => 'instant', 'name' => 'text']);
            $after = $position->instant('last_ordered_at')->getTimestamp();
            $name = $position->text('name');

            $groups = array_values(array_filter($groups, fn (array $g): bool => $g['last']->getTimestamp() < $after
                || ($g['last']->getTimestamp() === $after && strcmp($g['name'], $name) > 0)));
        }

        $hasMore = count($groups) > $limit;
        $shown = array_slice($groups, 0, $limit);
        $last = $shown === [] ? null : $shown[count($shown) - 1];

        $products = array_map(fn (array $g): array => [
            'name' => $g['name'],
            'sku' => $g['sku'],
            'total_qty' => $g['qty'],
            'order_count' => count($g['orders']),
            'last_ordered_at_jalali' => TehranDateTime::format($g['last']),
            'last_ordered_at_iso' => $g['last']->utc()->toIso8601ZuluString(),
        ], $shown);

        $next = $hasMore && $last !== null ? PageCursor::encode(['last_ordered_at' => $last['last'], 'name' => $last['name']]) : null;

        return new CursorPage($products, $next);
    }
}
