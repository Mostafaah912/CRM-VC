<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use App\Modules\Customers\Models\IdentityConflict;
use App\Support\PhoneMask;
use App\Support\TehranDateTime;

/**
 * @phpstan-type IdentityConflictShape array{created_at: string, status: string, woo_order_id: int|null, reason: string, customer_id: int|null, customer_phone: string|null, existing_name: string|null, incoming_name: string|null}
 *
 * One identity conflict as the review list shows it: when, its status, the order that raised it, the reason as stored, and
 * (P6-13) enough to actually review it — the existing customer (id, for a link and for PhoneRevealButton, plus its phone
 * ALWAYS masked, the same "list never carries a full number" rule CustomerListRow follows) and the two names being compared.
 * Names are not masked: every other list in this app (Customers, Orders, Segments) already shows display_name unmasked to
 * anyone with the page's own view permission — only the phone is permission-gated, project-wide. A `no_phone` conflict has no
 * customer (PRD §08 step 1) and carries none of this.
 */
final readonly class IdentityConflictRow
{
    /** The only columns of identity_conflicts the list may select. */
    public const COLUMNS = ['created_at', 'status', 'woo_order_id', 'reason', 'customer_id', 'existing_name', 'incoming_name'];

    public function __construct(
        public string $createdAt,
        public string $status,
        public ?int $wooOrderId,
        public string $reason,
        public ?int $customerId,
        public ?string $customerPhone,
        public ?string $existingName,
        public ?string $incomingName,
    ) {}

    public static function fromModel(IdentityConflict $conflict): self
    {
        return new self(
            TehranDateTime::format($conflict->created_at),
            $conflict->status->value,
            $conflict->woo_order_id,
            $conflict->reason,
            $conflict->customer_id,
            $conflict->customer === null ? null : PhoneMask::mask($conflict->customer->phone_normalized),
            $conflict->existing_name,
            $conflict->incoming_name,
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
            'customer_id' => $this->customerId,
            'customer_phone' => $this->customerPhone,
            'existing_name' => $this->existingName,
            'incoming_name' => $this->incomingName,
        ];
    }
}
