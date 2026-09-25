<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerEvent;
use App\Modules\Customers\Support\PageCursor;
use App\Modules\Customers\Support\TimelinePage;
use App\Support\TehranDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A customer's timeline (P3-04), read-only, newest first, paged by a CURSOR — the position of the last event shown, never an
 * offset — so a page never repeats or skips an event however many arrive meanwhile.
 *
 *  - Order is (happened_at DESC, id DESC); the id breaks ties, so the position is unique and the next page starts exactly after it.
 *  - The cursor is opaque to the browser: URL-safe base64 of {"happened_at": "<UTC ISO>", "id": <int>}, made and read ONLY by
 *    PageCursor (the one codec of every paged endpoint), decoded strictly and used only as bound values, never as SQL. It narrows
 *    the position inside ONE customer's events (customer_id is always in the query), so it cannot reach another customer's.
 *  - One extra row is read to know whether more exist, so has_more is exact even when a page is exactly full.
 *  - Only PAYLOAD_KEYS of an event's payload leave, and only plain scalars: the payload is free-form for its writer.
 *  - A date leaves as BOTH Jalali (Tehran time) and ISO 8601 UTC; the raw stored value is never sent.
 *
 * pageFor() is the endpoint's entry (a soft-deleted or missing customer is a 404); page() is for a caller that already loaded the
 * customer, so the Customer 360 page spends one query on its first page, not two. Nothing here writes, dispatches or logs.
 */
class CustomerTimelineService
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    /** The only payload keys the browser may see. Anything else a writer stored stays in the database. */
    public const PAYLOAD_KEYS = ['order_id', 'note', 'old_status', 'new_status', 'woo_order_id'];

    private const CURSOR_MAX_LENGTH = 200;

    public function pageFor(int $customerId, ?string $cursor = null, ?int $perPage = null): TimelinePage
    {
        $customer = Customer::query()->select('id')->findOrFail($customerId);

        return $this->page($customer, $cursor, $perPage);
    }

    /** The caller has already loaded the customer (so a soft-deleted one never gets here). */
    public function page(Customer $customer, ?string $cursor = null, ?int $perPage = null): TimelinePage
    {
        $limit = max(1, min($perPage ?? self::DEFAULT_PER_PAGE, self::MAX_PER_PAGE));

        $query = CustomerEvent::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('happened_at')
            ->orderByDesc('id')
            ->limit($limit + 1);

        if ($cursor !== null) {
            [$at, $id] = $this->position($cursor);
            $stamp = $at->format('Y-m-d H:i:sP');

            // One row comparison, so PostgreSQL can start the index scan AT the cursor instead of reading and discarding everything newer.
            $query->whereRowValues(['happened_at', 'id'], '<', [$stamp, $id]);
        }

        $rows = $query->get(['id', 'event_type', 'payload', 'happened_at']);
        $hasMore = $rows->count() > $limit;
        $shown = $rows->take($limit)->values();
        $last = $shown->last();

        $events = [];

        foreach ($shown as $row) {
            $at = CarbonImmutable::instance($row->happened_at);

            $events[] = [
                'id' => $row->id,
                'event_type' => $row->event_type->value,
                'happened_at_jalali' => TehranDateTime::format($at),
                'happened_at_iso' => $at->utc()->toIso8601ZuluString(),
                'payload' => $this->filterPayload($row->payload),
            ];
        }

        return new TimelinePage($events, $hasMore && $last !== null ? $this->encodeCursor(CarbonImmutable::instance($last->happened_at), $last->id) : null);
    }

    /** The opaque cursor for "everything older than this event". */
    public function encodeCursor(CarbonImmutable $happenedAt, int $id): string
    {
        return PageCursor::encode(['happened_at' => $happenedAt, 'id' => $id]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: int} the instant (UTC) and the id the cursor points at
     *
     * @throws InvalidArgumentException when the cursor is anything but exactly what encodeCursor() makes
     */
    public function decodeCursor(string $cursor): array
    {
        $position = PageCursor::decode($cursor, ['happened_at' => 'instant', 'id' => 'id'], self::CURSOR_MAX_LENGTH);

        return [$position->instant('happened_at'), $position->id('id')];
    }

    /**
     * The allowlisted, plain-scalar part of a stored payload, keys in allowlist order — or null when there is none, so the browser
     * never gets an empty object.
     *
     * @return array<string, string|int|bool|null>|null
     */
    public function filterPayload(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $allowed = [];

        // In allowlist order, not stored order: jsonb reorders keys, and the browser should not depend on the database's whim.
        foreach (self::PAYLOAD_KEYS as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];

            if (is_string($value) || is_int($value) || is_bool($value) || $value === null) {
                $allowed[$key] = $value;
            }
        }

        return $allowed === [] ? null : $allowed;
    }

    /**
     * @return array{0: CarbonImmutable, 1: int}
     *
     * @throws ValidationException a cursor that is not ours is the caller's input error (422), not a server fault
     */
    private function position(string $cursor): array
    {
        try {
            return $this->decodeCursor($cursor);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['cursor' => 'نشانگر صفحه معتبر نیست.']);
        }
    }
}
