<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNote;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DELETE /customers/{customer}/notes/{note} takes no body. The note is looked up UNDER its customer — a note id that belongs to a
 * different customer is a 404, so an id under the wrong URL can never be deleted — and a soft-deleted customer is a 404.
 * Whether THIS user may delete it is the service's 403.
 */
class NoteDestroyRequest extends FormRequest
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

    public function note(): CustomerNote
    {
        $customer = Customer::query()->select('id')->findOrFail((int) $this->route('customer'));

        return CustomerNote::query()->where('customer_id', $customer->id)->findOrFail((int) $this->route('note'));
    }

    public function actor(): User
    {
        $user = $this->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
