<?php

declare(strict_types=1);

namespace App\Http\Requests\Segments;

use Illuminate\Foundation\Http\FormRequest;

/** GET /segments/create takes no input; the form's whitelist comes from SegmentService::whitelist(). */
class SegmentCreateRequest extends FormRequest
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
}
