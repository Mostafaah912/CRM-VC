<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Models\User;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\LifecycleStage;
use App\Modules\Customers\Support\CustomerListFilters;
use App\Modules\Customers\Support\JalaliDay;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The query string of the customer list: every value is optional, an empty one means "not asked", and one that is not
 * understood is REJECTED — never ignored, never passed to the query. Access is the route's `permission:customers,view`.
 * Days are Jalali (YYYY/MM/DD or YYYY-MM-DD, ASCII or Persian digits).
 */
class CustomerListRequest extends FormRequest
{
    private const SEARCH_MAX = 100;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:'.self::SEARCH_MAX],
            'status' => ['nullable', Rule::enum(CustomerStatus::class)],
            'lifecycle_stage' => ['nullable', Rule::enum(LifecycleStage::class)],
            'province' => ['nullable', 'string', 'max:60'],
            'city' => ['nullable', 'string', 'max:80'],
            'needs_review' => ['nullable', 'boolean'],
            'first_seen_from' => ['nullable', 'string', $this->jalaliDay()],
            'first_seen_to' => ['nullable', 'string', $this->jalaliDay()],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $from = JalaliDay::start((string) $this->input('first_seen_from'));
            $to = JalaliDay::start((string) $this->input('first_seen_to'));

            if (! $validator->errors()->any() && $from !== null && $to !== null && $from->greaterThan($to)) {
                $validator->errors()->add('first_seen_to', 'The end of the range must not be before its start.');
            }
        });
    }

    public function filters(): CustomerListFilters
    {
        return new CustomerListFilters(
            search: $this->filled('search') ? (string) $this->input('search') : null,
            status: $this->enum('status', CustomerStatus::class),
            lifecycleStage: $this->enum('lifecycle_stage', LifecycleStage::class),
            province: $this->filled('province') ? (string) $this->input('province') : null,
            city: $this->filled('city') ? (string) $this->input('city') : null,
            needsReview: $this->filled('needs_review') ? $this->boolean('needs_review') : null,
            firstSeenFrom: $this->filled('first_seen_from') ? JalaliDay::start((string) $this->input('first_seen_from')) : null,
            firstSeenBefore: $this->filled('first_seen_to') ? JalaliDay::nextStart((string) $this->input('first_seen_to')) : null,
        );
    }

    /**
     * The filters as typed, for the form to keep its values.
     *
     * @return array{search: string|null, status: string|null, lifecycle_stage: string|null, province: string|null, city: string|null, needs_review: bool|null, first_seen_from: string|null, first_seen_to: string|null}
     */
    public function echo(): array
    {
        return [
            'search' => $this->filled('search') ? (string) $this->input('search') : null,
            'status' => $this->filled('status') ? (string) $this->input('status') : null,
            'lifecycle_stage' => $this->filled('lifecycle_stage') ? (string) $this->input('lifecycle_stage') : null,
            'province' => $this->filled('province') ? (string) $this->input('province') : null,
            'city' => $this->filled('city') ? (string) $this->input('city') : null,
            'needs_review' => $this->filled('needs_review') ? $this->boolean('needs_review') : null,
            'first_seen_from' => $this->filled('first_seen_from') ? (string) $this->input('first_seen_from') : null,
            'first_seen_to' => $this->filled('first_seen_to') ? (string) $this->input('first_seen_to') : null,
        ];
    }

    public function page(): int
    {
        return max(1, $this->integer('page', 1));
    }

    public function viewer(): User
    {
        $user = $this->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }

    private function jalaliDay(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (JalaliDay::start((string) $value) === null) {
                $fail("The {$attribute} must be a Jalali day written YYYY/MM/DD.");
            }
        };
    }
}
