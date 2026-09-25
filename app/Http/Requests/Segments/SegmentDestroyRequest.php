<?php

declare(strict_types=1);

namespace App\Http\Requests\Segments;

use App\Models\User;
use App\Modules\Segments\Models\Segment;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

/** DELETE /segments/{segment} takes no body; whether it may be deleted (is_system) is the Service's refusal. */
class SegmentDestroyRequest extends FormRequest
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

    public function actor(): User
    {
        $user = $this->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
