<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Modules\Customers\Support\JalaliDay;
use App\Modules\Orders\Services\OrderListService;
use App\Modules\Orders\Support\OrderListFilters;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The query string of the order list: every value is optional, an empty one means "not asked", and one that is not understood is
 * REJECTED — never ignored. `status` is checked against the statuses actually stored (never a hardcoded list — CLAUDE.md §3).
 * Days are Jalali (YYYY/MM/DD or YYYY-MM-DD, ASCII or Persian digits), same convention as the customer list (P3-01).
 */
class OrderListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Laravel resolves rules() through the container (FormRequest::getValidatorInstance()), so a service can be asked for here
     * the same way a controller method asks for one.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(OrderListService $orders): array
    {
        return [
            'woo_order_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:30', Rule::in($orders->statusOptions())],
            'is_realized' => ['nullable', 'boolean'],
            'needs_phone_review' => ['nullable', 'boolean'],
            'ordered_from' => ['nullable', 'string', $this->jalaliDay()],
            'ordered_to' => ['nullable', 'string', $this->jalaliDay()],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $from = JalaliDay::start((string) $this->input('ordered_from'));
            $to = JalaliDay::start((string) $this->input('ordered_to'));

            if (! $validator->errors()->any() && $from !== null && $to !== null && $from->greaterThan($to)) {
                $validator->errors()->add('ordered_to', 'The end of the range must not be before its start.');
            }
        });
    }

    public function filters(): OrderListFilters
    {
        return new OrderListFilters(
            wooOrderId: $this->filled('woo_order_id') ? $this->integer('woo_order_id') : null,
            status: $this->filled('status') ? (string) $this->input('status') : null,
            isRealized: $this->filled('is_realized') ? $this->boolean('is_realized') : null,
            needsPhoneReview: $this->filled('needs_phone_review') ? $this->boolean('needs_phone_review') : null,
            orderedFrom: $this->filled('ordered_from') ? JalaliDay::start((string) $this->input('ordered_from')) : null,
            orderedBefore: $this->filled('ordered_to') ? JalaliDay::nextStart((string) $this->input('ordered_to')) : null,
        );
    }

    /**
     * The filters as typed, for the form to keep its values.
     *
     * @return array{woo_order_id: int|null, status: string|null, is_realized: bool|null, needs_phone_review: bool|null, ordered_from: string|null, ordered_to: string|null}
     */
    public function echo(): array
    {
        return [
            'woo_order_id' => $this->filled('woo_order_id') ? $this->integer('woo_order_id') : null,
            'status' => $this->filled('status') ? (string) $this->input('status') : null,
            'is_realized' => $this->filled('is_realized') ? $this->boolean('is_realized') : null,
            'needs_phone_review' => $this->filled('needs_phone_review') ? $this->boolean('needs_phone_review') : null,
            'ordered_from' => $this->filled('ordered_from') ? (string) $this->input('ordered_from') : null,
            'ordered_to' => $this->filled('ordered_to') ? (string) $this->input('ordered_to') : null,
        ];
    }

    public function page(): int
    {
        return max(1, $this->integer('page', 1));
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
