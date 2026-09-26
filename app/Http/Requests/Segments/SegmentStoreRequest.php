<?php

declare(strict_types=1);

namespace App\Http\Requests\Segments;

use App\Models\User;
use App\Modules\Segments\Models\Segment;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /segments. `rule` is only required/array here — its content is RuleValidator's job
 * (SegmentService::create()), never re-implemented in this FormRequest. `name` is unique
 * case-insensitively, matching the `segments_name_unique` (lower(name)) DB index: Postgres `ILIKE`
 * without wildcards is a case-insensitive exact match, so this needs no raw SQL (CLAUDE.md §3's ban).
 * A race between this check and the INSERT is still possible; SegmentService::create() catches the
 * DB's unique-violation as a backstop.
 */
class SegmentStoreRequest extends FormRequest
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

    /** @return array{name: string, description: string|null, rule: array<mixed>} */
    public function attributes(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'description' => $this->filled('description') ? $this->string('description')->toString() : null,
            'rule' => (array) $this->input('rule'),
        ];
    }

    public function author(): User
    {
        $user = $this->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }

    private function uniqueName(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (Segment::query()->where('name', 'ilike', (string) $value)->exists()) {
                $fail('این نام قبلاً برای سگمنت دیگری استفاده شده است.');
            }
        };
    }
}
