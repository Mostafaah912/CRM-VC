<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Modules\Customers\Support\CursorPage;
use App\Modules\Customers\Support\PageCursor;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /customers/{customer}/orders|products|notes?cursor=&per_page= — the paging input the three Customer 360 lists share. The
 * cursor is opaque here (only its size is checked; PageCursor alone knows its format and the service rejects a bad one); per_page is
 * a whole number from 1 to MAX_PER_PAGE (a bigger one is refused, not clamped). Whether the customer exists and is not soft-deleted
 * is the service's 404, decided after the route's auth and permission, so a viewer without customers.view always gets 403.
 */
class CustomerPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string', 'max:'.PageCursor::DEFAULT_MAX_LENGTH],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.CursorPage::MAX_PER_PAGE],
        ];
    }

    public function customerId(): int
    {
        return (int) $this->route('customer');
    }

    public function cursor(): ?string
    {
        return $this->filled('cursor') ? $this->string('cursor')->toString() : null;
    }

    public function perPage(): ?int
    {
        return $this->filled('per_page') ? $this->integer('per_page') : null;
    }
}
