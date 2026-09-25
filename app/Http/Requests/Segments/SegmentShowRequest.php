<?php

declare(strict_types=1);

namespace App\Http\Requests\Segments;

use App\Modules\Segments\Models\Segment;
use Illuminate\Foundation\Http\FormRequest;

/** GET /segments/{segment} — the detail page (PRD §25), plus its member list's `page` query param. */
class SegmentShowRequest extends FormRequest
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

    public function segmentModel(): Segment
    {
        return Segment::query()->findOrFail((int) $this->route('segment'));
    }

    public function page(): int
    {
        return max(1, $this->integer('page', 1));
    }
}
