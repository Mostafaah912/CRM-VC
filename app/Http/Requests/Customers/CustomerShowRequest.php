<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /customers/{customer} takes no input beyond the route id (whereNumber on the route). Whether that customer exists — and is
 * not soft-deleted — is CustomerShowService's 404, decided after the route's auth and permission, so a viewer without
 * customers.view always gets 403 and never learns whether an id exists.
 */
class CustomerShowRequest extends FormRequest
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

    public function customerId(): int
    {
        return (int) $this->route('customer');
    }
}
