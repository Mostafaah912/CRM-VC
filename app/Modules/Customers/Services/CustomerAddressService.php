<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use Illuminate\Support\Facades\DB;

/**
 * `customer_addresses` (PRD §09): one row per (customer, type) — "the customer's current billing/
 * shipping address", not an append-only history. PRD gives no uniqueness constraint on the table, so
 * this is a reasoned default (P6-14 phase 4): an order re-sync upserting one row per order would grow
 * the table unbounded for no documented benefit, and every other "current state from the latest order"
 * value in this module (names, province/city on `customers` itself) already works the same way — most
 * recent non-empty value wins, trusted to the caller's own call order (the sync pipeline processes
 * oldest-modified-first), not a timestamp comparison here.
 *
 * `is_default` is always true: with exactly one row per type, that row is inherently the default for
 * its type.
 */
final class CustomerAddressService
{
    private const PROVINCE_MAX = 60;

    private const CITY_MAX = 80;

    private const POSTCODE_MAX = 20;

    /**
     * A no-op when every field is empty — there is nothing worth storing, and an order with no
     * address data must never overwrite an address a previous order already supplied.
     */
    public function upsert(int $customerId, string $type, ?string $province, ?string $city, ?string $address, ?string $postcode): void
    {
        $province = $this->clean($province, self::PROVINCE_MAX);
        $city = $this->clean($city, self::CITY_MAX);
        $address = $address === null || trim($address) === '' ? null : $address;
        $postcode = $this->clean($postcode, self::POSTCODE_MAX);

        if ($province === null && $city === null && $address === null && $postcode === null) {
            return;
        }

        $now = now();
        $isNew = ! DB::table('customer_addresses')->where('customer_id', $customerId)->where('type', $type)->exists();

        DB::table('customer_addresses')->updateOrInsert(
            ['customer_id' => $customerId, 'type' => $type],
            [
                'province' => $province,
                'city' => $city,
                'address' => $address,
                'postcode' => $postcode,
                'is_default' => true,
                'updated_at' => $now,
                ...($isNew ? ['created_at' => $now] : []),
            ],
        );
    }

    private function clean(?string $value, int $max): ?string
    {
        $value = trim($value ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
