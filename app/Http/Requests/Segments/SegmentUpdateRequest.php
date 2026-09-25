<?php

declare(strict_types=1);

namespace App\Http\Requests\Segments;

use App\Models\User;
use App\Modules\Segments\Models\Segment;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /segments/{segment}. Same shape as SegmentStoreRequest — see it for the `rule`/uniqueness
 * reasoning; the uniqueness check here excludes the segment's own current row.
 */
class SegmentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', $this->uniqueName()],
            'description' => ['nullable', 'string', 'max:1000'],
            'rule' => ['required', 'array'],
        ];
    }

    public function segmentModel(): Segment
    {
        return Segment::query()->findOrFail((int) $this->route('segment'));
    }

    /** @return array{name: string, description: string|null, rule: array<mixed>} */
    public function attributes(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'description' => $this->filled('description') ? $this->string('description')->toString() : null,
            'rule' => (array) $this->input('rule'),
        ];
    }

    public function actor(): User
    {
        $user = $this->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }

    private function uniqueName(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $exists = Segment::query()
                ->where('name', 'ilike', (string) $value)
                ->whereKeyNot((int) $this->route('segment'))
                ->exists();

            if ($exists) {
                $fail('این نام قبلاً برای سگمنت دیگری استفاده شده است.');
            }
        };
    }
}
