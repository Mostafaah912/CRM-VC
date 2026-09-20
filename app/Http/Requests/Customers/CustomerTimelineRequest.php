<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Modules\Customers\Services\CustomerTimelineService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /customers/{customer}/timeline?cursor=&per_page=. The cursor is opaque here — only its size is checked; the service alone
 * knows its format and rejects a bad one. per_page is a whole number from 1 to MAX_PER_PAGE (a bigger one is refused, not clamped).
 * Whether the customer exists and is not soft-deleted is the service's 404, decided after the route's auth and permission.
 */
class CustomerTimelineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.CustomerTimelineService::MAX_PER_PAGE],
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
