<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

/** GET /orders/{order}. No input beyond the route id (whereNumber). Whether the order exists is OrderShowService's 404. */
class OrderShowRequest extends FormRequest
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

    public function orderId(): int
    {
        return (int) $this->route('order');
    }
}
