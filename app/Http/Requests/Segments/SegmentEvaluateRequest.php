<?php

declare(strict_types=1);

namespace App\Http\Requests\Segments;

use App\Modules\Segments\Models\Segment;
use Illuminate\Foundation\Http\FormRequest;

/** POST /segments/{segment}/evaluate takes no body — it just queues EvaluateSegmentJob for this segment. */
class SegmentEvaluateRequest extends FormRequest
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
