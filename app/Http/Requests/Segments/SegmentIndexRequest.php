<?php

declare(strict_types=1);

namespace App\Http\Requests\Segments;

use Illuminate\Foundation\Http\FormRequest;

/** GET /segments — PRD §25 names no list filters, only pagination. */
class SegmentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['page' => ['nullable', 'integer', 'min:1', 'max:100000']];
    }

    public function page(): int
    {
        return max(1, $this->integer('page', 1));
    }
}
