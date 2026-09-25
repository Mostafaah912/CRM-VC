<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use LogicException;

/**
 * Decides whether a Woo order status counts as realized revenue (PRD §07/§11: `is_realized`).
 * Status is a raw, store-defined Woo slug (ARCHITECTURE.md P1-03), so there is no status Enum and no
 * list in code: the answer comes only from config('woo.realized_statuses'), read on every call.
 * Exact match. An unknown, custom, differently-cased or prefixed status is NOT realized — the safe
 * default is "not revenue", never "completed". Refund amounts are not an input: a partial refund leaves
 * Woo's status alone, a full refund through Woo sets `refunded`; refund bookkeeping is P2-07.
 */
final class OrderStatusMapper
{
    public function isRealized(string $wooStatus): bool
    {
        return in_array($wooStatus, $this->realizedStatuses(), true);
    }

    /** @return list<string> */
    private function realizedStatuses(): array
    {
        $configured = config('woo.realized_statuses');

        if (! is_array($configured)) {
            throw new LogicException('config woo.realized_statuses must be a list of status slugs.');
        }

        $statuses = [];

        foreach ($configured as $status) {
            if (! is_string($status) || $status === '') {
                throw new LogicException('config woo.realized_statuses must contain only non-empty status slugs.');
            }

            $statuses[] = $status;
        }

        return $statuses;
    }
}
