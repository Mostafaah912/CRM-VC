<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Models\User;
use App\Modules\Core\Services\PermissionService;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\LifecycleStage;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerListFilters;
use App\Modules\Customers\Support\CustomerListRow;
use App\Support\Exceptions\InvalidPhoneException;
use App\Support\PhoneNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * P3-01, read-only: the customer list. Search, filters, offset pagination and phone masking live HERE, never in a controller.
 *
 *  - Search is one box. The text is matched against display_name with ILIKE (the trigram GIN index on display_name serves it;
 *    the user's % and _ are escaped, so they are characters, not wildcards). It is ALSO run through PhoneNormalizer — any
 *    Iranian format, Persian/Arabic digits, direction marks — and when that gives a valid number the customer with that exact
 *    phone matches too. A text that is not a valid phone is simply a name search: never an error.
 *  - Filters narrow with AND, and only on columns of `customers`. There is deliberately no filter on customer_metrics
 *    (RFM/churn/CLV): that table is Sprint 4's and still empty.
 *  - Order is newest-created first, ties by id: one stable order, so a page never repeats or skips a customer.
 *  - Phones: masked unless the viewer holds customers.view_full_phone (decided by PermissionService, deny > allow > role > closed).
 *    The row class does the masking, so the full number is not in the response at all for a masked viewer.
 *
 * @phpstan-import-type CustomerListShape from CustomerListRow
 */
final class CustomerListService
{
    private const PER_PAGE = 25;

    public function __construct(private readonly PermissionService $permissions) {}

    /** @return LengthAwarePaginator<int, CustomerListShape> */
    public function paginate(User $viewer, CustomerListFilters $filters, int $page = 1, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $fullPhone = $this->permissions->allows($viewer, 'customers', 'view_full_phone');
        $term = $filters->search === null ? '' : trim($filters->search);

        return Customer::query()
            ->select(CustomerListRow::COLUMNS)
            ->when($term !== '', fn (Builder $query) => $this->search($query, $term))
            ->when($filters->status !== null, fn (Builder $query) => $query->where('status', $filters->status))
            ->when($filters->lifecycleStage !== null, fn (Builder $query) => $query->where('lifecycle_stage', $filters->lifecycleStage))
            ->when($filters->province !== null, fn (Builder $query) => $query->where('province', $filters->province))
            ->when($filters->city !== null, fn (Builder $query) => $query->where('city', $filters->city))
            ->when($filters->needsReview !== null, fn (Builder $query) => $query->where('needs_review', $filters->needsReview))
            ->when($filters->firstSeenFrom !== null, fn (Builder $query) => $query->where('first_seen_at', '>=', $filters->firstSeenFrom))
            ->when($filters->firstSeenBefore !== null, fn (Builder $query) => $query->where('first_seen_at', '<', $filters->firstSeenBefore))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString()
            ->through(fn (Customer $customer): array => CustomerListRow::fromModel($customer, $fullPhone)->toArray());
    }

    /**
     * What the filter form offers: every status and lifecycle stage, the provinces that exist, and the cities that exist (of the
     * chosen province, when one is chosen).
     *
     * @return array{statuses: list<string>, lifecycle_stages: list<string>, provinces: list<string>, cities: list<string>}
     */
    public function filterOptions(?string $province): array
    {
        return [
            'statuses' => array_map(fn (CustomerStatus $status): string => $status->value, CustomerStatus::cases()),
            'lifecycle_stages' => array_map(fn (LifecycleStage $stage): string => $stage->value, LifecycleStage::cases()),
            'provinces' => $this->distinct(Customer::query(), 'province'),
            'cities' => $this->distinct(
                Customer::query()->when($province !== null, fn (Builder $query) => $query->where('province', $province)),
                'city',
            ),
        ];
    }

    /**
     * @param  Builder<Customer>  $query
     * @return list<string>
     */
    private function distinct(Builder $query, string $column): array
    {
        return array_values(array_map(
            fn (mixed $value): string => (string) $value,
            $query->whereNotNull($column)->distinct()->orderBy($column)->pluck($column)->all(),
        ));
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    private function search(Builder $query, string $term): Builder
    {
        $phone = $this->normalizedPhone($term);

        return $query->where(function (Builder $match) use ($term, $phone): void {
            $match->where('display_name', 'ilike', '%'.$this->escapeLike($term).'%');

            if ($phone !== null) {
                $match->orWhere('phone_normalized', $phone);
            }
        });
    }

    private function normalizedPhone(string $term): ?string
    {
        try {
            return PhoneNormalizer::normalize($term);
        } catch (InvalidPhoneException) {
            return null;
        }
    }

    /** `\`, `%` and `_` are LIKE syntax: typed by a user they are just characters. */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
