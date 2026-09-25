<?php

declare(strict_types=1);

namespace App\Http\Requests\Segments;

use App\Modules\Segments\Models\Segment;
use Illuminate\Foundation\Http\FormRequest;

/** GET /segments/{segment}/edit takes no input beyond the route id; a soft-deleted segment is a 404. */
class SegmentEditRequest extends FormRequest
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

    public function segmentModel(): Segment
    {
        return Segment::query()->findOrFail((int) $this->route('segment'));
    }
}
