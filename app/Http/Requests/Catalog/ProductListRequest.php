<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Support\ProductListFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The query string of the product list: every value is optional, an empty one means "not asked", and one that is not
 * understood is REJECTED — never ignored. `status` is Catalog's own enum (ProductStatus), not Woo's raw order-status
 * string, so validating it against a fixed set is fine here (unlike orders.status — CLAUDE.md §3).
 */
class ProductListRequest extends FormRequest
{
    private const NAME_MAX = 250;

    private const SKU_MAX = 80;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:'.self::NAME_MAX],
            'sku' => ['nullable', 'string', 'max:'.self::SKU_MAX],
            'status' => ['nullable', Rule::enum(ProductStatus::class)],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function filters(): ProductListFilters
    {
        return new ProductListFilters(
            name: $this->filled('name') ? (string) $this->input('name') : null,
            sku: $this->filled('sku') ? (string) $this->input('sku') : null,
            status: $this->enum('status', ProductStatus::class),
        );
    }

    /**
     * The filters as typed, for the form to keep its values.
     *
     * @return array{name: string|null, sku: string|null, status: string|null}
     */
    public function echo(): array
    {
        return [
            'name' => $this->filled('name') ? (string) $this->input('name') : null,
            'sku' => $this->filled('sku') ? (string) $this->input('sku') : null,
            'status' => $this->filled('status') ? (string) $this->input('status') : null,
        ];
    }

    public function page(): int
    {
        return max(1, $this->integer('page', 1));
    }
}
