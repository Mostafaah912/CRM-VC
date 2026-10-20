<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Modules\Ai\Enums\AiInsightType;
use Illuminate\Validation\Rule;

/** The analysis type (daily_brief | weekly_review only) plus the paging of the history list. Route middleware already checked ai.view. */
class AnalystTypeRequest extends AnalystPageRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in([AiInsightType::DailyBrief->value, AiInsightType::WeeklyReview->value])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:25'],
        ];
    }

    public function type(): AiInsightType
    {
        return AiInsightType::from((string) $this->validated('type'));
    }

    public function pageNumber(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 10);
    }
}
