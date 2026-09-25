<?php

declare(strict_types=1);

namespace App\Http\Requests\Segments;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /segments/preview {rule}. A draft rule from the Rule Builder (P5-05), not yet saved as a
 * Segment — permission is enforced by the route's `permission:segments,create` middleware, not
 * here. This only checks `rule` is present and shaped as an array; the real validation (whitelist,
 * depth, value shapes) is RuleValidator's, run inside SegmentService::preview() itself.
 */
class SegmentPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['rule' => ['required', 'array']];
    }

    /** @return array<mixed> */
    public function rule(): array
    {
        return (array) $this->validated('rule');
    }
}
