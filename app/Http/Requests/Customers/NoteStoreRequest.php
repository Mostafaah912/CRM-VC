<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /customers/{customer}/notes {body}. The body is required, text, at most 2000 characters (leading and trailing whitespace
 * is trimmed by the framework first, so a blank body is "required" and fails). The customer is looked up HERE, after the route's
 * auth and permissions, so a viewer who may not write always gets 403 and never a 404 that reveals whether an id exists; a
 * soft-deleted customer is a 404.
 */
class NoteStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:2000']];
    }

    public function customer(): Customer
    {
        return Customer::query()->findOrFail((int) $this->route('customer'));
    }

    public function author(): User
    {
        $user = $this->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }

    public function body(): string
    {
        return $this->string('body')->toString();
    }
}
