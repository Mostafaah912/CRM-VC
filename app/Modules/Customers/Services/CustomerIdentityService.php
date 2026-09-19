<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Enums\IdentityConflictStatus;
use App\Modules\Customers\Enums\IdentitySource;
use App\Modules\Customers\Events\CustomerCreated;
use App\Modules\Customers\Events\IdentityConflictDetected;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerIdentity;
use App\Modules\Customers\Models\IdentityConflict;
use App\Support\Exceptions\InvalidPhoneException;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Resolves who a Woo order belongs to (PRD §08). One normalized phone = one customer, always.
 *
 *  - The phone goes through PhoneNormalizer first; an invalid phone throws its InvalidPhoneException
 *    and nothing is written (what an order with no usable phone does is the caller's decision, P2-06).
 *  - Name, email, Woo customer id and address are never identity keys. There is no email input at all.
 *  - A last name that is not compatible with the existing one NEVER splits the customer, drops the
 *    order or overwrites the name: it becomes a pending identity_conflicts row for human review.
 *
 * Concurrency: customers.phone_normalized is UNIQUE, so the customer is created with
 * INSERT ... ON CONFLICT DO NOTHING (a lost race is not an error and does not abort the transaction),
 * then read back FOR UPDATE. That row lock serialises concurrent resolutions of the same phone, which
 * is what makes the conflict check-then-insert below safe without a unique index on identity_conflicts.
 * The whole resolution is one transaction: no half-written identity, name or conflict.
 */
final class CustomerIdentityService
{
    public const REASON_LAST_NAME_MISMATCH = 'last_name_mismatch';

    private const FIRST_NAME_MAX = 80;

    private const LAST_NAME_MAX = 80;

    private const DISPLAY_NAME_MAX = 160;

    private const RAW_PHONE_MAX = 25;

    private const SOURCE_ID_MAX = 64;

    public function __construct(private readonly PersonNameNormalizer $names) {}

    /**
     * @throws InvalidPhoneException when the phone is not a valid Iranian mobile number
     */
    public function resolve(
        ?string $phoneRaw,
        ?string $firstName,
        ?string $lastName,
        IdentitySource $source,
        string $sourceId,
        ?int $wooOrderId = null,
    ): Customer {
        $phone = PhoneNormalizer::normalize($phoneRaw);
        $sourceId = trim($sourceId);

        if ($sourceId === '' || mb_strlen($sourceId) > self::SOURCE_ID_MAX) {
            throw new InvalidArgumentException('The identity source id must be 1-'.self::SOURCE_ID_MAX.' characters.');
        }

        $first = $this->clean($firstName, self::FIRST_NAME_MAX);
        $last = $this->clean($lastName, self::LAST_NAME_MAX);
        $rawPhone = $this->clean($phoneRaw, self::RAW_PHONE_MAX);

        return DB::transaction(function () use ($phone, $rawPhone, $first, $last, $source, $sourceId, $wooOrderId): Customer {
            $customer = $this->lockedByPhone($phone);
            $created = false;

            if ($customer === null) {
                $now = now();
                $created = Customer::query()->insertOrIgnore([[
                    'phone_normalized' => $phone,
                    'phone_raw_last' => $rawPhone,
                    'first_name' => $first,
                    'last_name' => $last,
                    'display_name' => $this->compose($first, $last),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]]) === 1;

                $customer = $this->lockedByPhone($phone)
                    ?? throw new LogicException('A customer row vanished between its insert and its read.');
            }

            CustomerIdentity::query()->insertOrIgnore([[
                'customer_id' => $customer->id,
                'source' => $source->value,
                'source_id' => $sourceId,
                'created_at' => now(),
            ]]);

            $conflict = $created ? null : $this->reconcileExisting($customer, $rawPhone, $first, $last, $wooOrderId);

            if ($created) {
                DB::afterCommit(fn () => CustomerCreated::dispatch($customer->id));
            }

            if ($conflict !== null) {
                DB::afterCommit(fn () => IdentityConflictDetected::dispatch($customer->id, $conflict->id, $wooOrderId));
            }

            return $customer;
        });
    }

    /**
     * resolve() for a Woo order: a registered Woo user is identified by their Woo customer id, a guest by
     * the order itself (PRD §08 sources). Lets callers stay out of Customers' enums.
     *
     * @throws InvalidPhoneException when the phone is not a valid Iranian mobile number
     */
    public function resolveForWooOrder(
        ?string $phoneRaw,
        ?string $firstName,
        ?string $lastName,
        ?int $wooCustomerId,
        int $wooOrderId,
    ): Customer {
        return $wooCustomerId === null
            ? $this->resolve($phoneRaw, $firstName, $lastName, IdentitySource::WooGuestOrder, (string) $wooOrderId, $wooOrderId)
            : $this->resolve($phoneRaw, $firstName, $lastName, IdentitySource::WooUser, (string) $wooCustomerId, $wooOrderId);
    }

    /** Soft-deleted customers included: the UNIQUE index covers them too, and a deleted customer's order must still attach. */
    private function lockedByPhone(string $phone): ?Customer
    {
        return Customer::withTrashed()->where('phone_normalized', $phone)->lockForUpdate()->first();
    }

    /**
     * An existing customer: record a conflict if the last name is incompatible (and leave its name
     * alone), otherwise move names to the most recent non-empty values. Returns the NEW conflict, if any.
     */
    private function reconcileExisting(Customer $customer, ?string $rawPhone, ?string $first, ?string $last, ?int $wooOrderId): ?IdentityConflict
    {
        $conflict = null;

        if ($this->lastNamesConflict($customer->last_name, $last)) {
            $conflict = $this->recordConflict($customer, $this->compose($first, $last), $wooOrderId);
        } else {
            $this->adoptNames($customer, $first, $last);
        }

        if ($rawPhone !== null) {
            $customer->phone_raw_last = $rawPhone;
        }

        if ($customer->isDirty()) {
            $customer->save();
        }

        return $conflict;
    }

    private function lastNamesConflict(?string $existing, ?string $incoming): bool
    {
        $existingKey = $this->names->normalize($existing ?? '');
        $incomingKey = $this->names->normalize($incoming ?? '');

        return $existingKey !== '' && $incomingKey !== '' && $existingKey !== $incomingKey;
    }

    /** "Most recent non-empty value" (PRD §08 step 5): an empty incoming name never blanks a known one. */
    private function adoptNames(Customer $customer, ?string $first, ?string $last): void
    {
        if ($first !== null) {
            $customer->first_name = $first;
        }

        if ($last !== null) {
            $customer->last_name = $last;
        }

        if ($customer->isDirty(['first_name', 'last_name'])) {
            $customer->display_name = $this->compose($customer->first_name, $customer->last_name);
        }
    }

    /** One pending row per (customer, incoming name, order) — a resync or a resolved review never re-raises it. */
    private function recordConflict(Customer $customer, ?string $incomingName, ?int $wooOrderId): ?IdentityConflict
    {
        $alreadyRecorded = IdentityConflict::query()
            ->where('customer_id', $customer->id)
            ->where('incoming_name', $incomingName)
            ->when(
                $wooOrderId === null,
                fn ($query) => $query->whereNull('woo_order_id'),
                fn ($query) => $query->where('woo_order_id', $wooOrderId),
            )
            ->exists();

        if ($alreadyRecorded) {
            return null;
        }

        return IdentityConflict::create([
            'customer_id' => $customer->id,
            'existing_name' => $customer->display_name ?? $this->compose($customer->first_name, $customer->last_name),
            'incoming_name' => $incomingName,
            'woo_order_id' => $wooOrderId,
            'reason' => self::REASON_LAST_NAME_MISMATCH,
            'status' => IdentityConflictStatus::Pending,
        ]);
    }

    /** Trimmed, '' -> null, cut to the column width: an overlong value must never reject an order. */
    private function clean(?string $value, int $max): ?string
    {
        $value = trim($value ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function compose(?string $first, ?string $last): ?string
    {
        $full = trim(($first ?? '').' '.($last ?? ''));

        return $full === '' ? null : mb_substr($full, 0, self::DISPLAY_NAME_MAX);
    }
}
