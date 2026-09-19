<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use App\Modules\Customers\Models\IdentityConflict;
use App\Support\TehranDateTime;

/**
 * @phpstan-type IdentityConflictShape array{created_at: string, status: string, woo_order_id: int|null, reason: string}
 *
 * One identity conflict as the review list shows it: when, its status, the order that raised it and the reason as stored.
 * Built from COLUMNS only — never the customer, the two names or anything joined in.
 */
final readonly class IdentityConflictRow
{
    /** The only columns of identity_conflicts the list may select. */
    public const COLUMNS = ['created_at', 'status', 'woo_order_id', 'reason'];

    public function __construct(
        public string $createdAt,
        public string $status,
        public ?int $wooOrderId,
        public string $reason,
    ) {}

    public static function fromModel(IdentityConflict $conflict): self
    {
        return new self(
            TehranDateTime::format($conflict->created_at),
            $conflict->status->value,
            $conflict->woo_order_id,
            $conflict->reason,
        );
    }

    /** @return IdentityConflictShape */
    public function toArray(): array
    {
        return [
            'created_at' => $this->createdAt,
            'status' => $this->status,
            'woo_order_id' => $this->wooOrderId,
            'reason' => $this->reason,
        ];
    }
}
