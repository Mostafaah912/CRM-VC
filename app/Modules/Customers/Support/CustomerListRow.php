<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use App\Modules\Customers\Models\Customer;
use App\Support\JalaliDate;
use App\Support\PhoneMask;

/**
 * One customer as the list shows it. Built from COLUMNS only, so an email, the raw phone or the first/last name is never even
 * loaded. The phone is ALWAYS the masked form — for every viewer, whatever they may do: the list has no path that carries a full
 * number. A viewer with customers.view_full_phone reveals one number at a time through PhoneRevealService (audited).
 *
 * @phpstan-type CustomerListShape array{id: int, display_name: string|null, phone: string, status: string, lifecycle_stage: string, province: string|null, city: string|null, first_seen_at: string|null, needs_review: bool}
 */
final readonly class CustomerListRow
{
    /** The only columns of customers the list may select. */
    public const COLUMNS = ['id', 'phone_normalized', 'display_name', 'status', 'lifecycle_stage', 'province', 'city', 'first_seen_at', 'needs_review'];

    public static function fromModel(Customer $customer): self
    {
        return new self($customer);
    }

    private function __construct(private Customer $customer) {}

    /** @return CustomerListShape */
    public function toArray(): array
    {
        $c = $this->customer;

        return [
            'id' => $c->id,
            'display_name' => $c->display_name,
            'phone' => PhoneMask::mask($c->phone_normalized),
            'status' => $c->status->value,
            'lifecycle_stage' => $c->lifecycle_stage->value,
            'province' => $c->province,
            'city' => $c->city,
            'first_seen_at' => $c->first_seen_at === null ? null : JalaliDate::format($c->first_seen_at),
            'needs_review' => $c->needs_review,
        ];
    }
}
