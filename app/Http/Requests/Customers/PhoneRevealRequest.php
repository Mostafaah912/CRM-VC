<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Modules\Customers\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /customers/{customer}/reveal-phone takes no body. What it validates is the customer: it must exist and not be
 * soft-deleted (else 404). It is looked up HERE, after the route's auth, permission and throttle, so a viewer who may not
 * reveal always gets 403 — never a 404 that would tell them whether a customer id exists.
 */
class PhoneRevealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, never> */
    public function rules(): array
    {
        return [];
    }

    public function customer(): Customer
    {
        return Customer::query()->findOrFail((int) $this->route('customer'));
    }
}
