<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Modules\Analytics\Support\DashboardPeriod;
use App\Modules\Customers\Support\JalaliDay;
use App\Modules\Metrics\Enums\ChurnRiskLevel;
use App\Modules\Metrics\Enums\RfmSegment;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /internal/drill/{widget}` (P6-07/P6-08): the same optional Jalali `from`/`to` period as
 * `DashboardRequest` (duplicated, not shared — CLAUDE.md §5: "three similar lines is better than a
 * premature abstraction" for ~15 lines), plus a small set of widget-specific params, each required only
 * for the widget that needs it. Validating an Enum-backed value here (Http layer) rather than inside
 * `DrillService` (Analytics module) is deliberate: importing `RfmSegment`/`ChurnRiskLevel` cross-module
 * from Analytics is exactly the boundary violation P6-06 already hit — a FormRequest is not a module, so
 * it may import either freely. `affinity_level` is named differently from `level` (churn_level's own
 * param) on purpose: the two widgets need different valid value sets (ChurnRiskLevel vs the three
 * customer-shaped AffinityLevel values), so one shared `level` name would collide.
 */
final class DrillRequest extends FormRequest
{
    private const DEFAULT_DAYS = 30;

    private const MAX_DAYS = 366;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $widget = (string) $this->route('widget');

        return [
            'from' => ['nullable', 'string', 'required_with:to', $this->jalaliDay()],
            'to' => ['nullable', 'string', 'required_with:from', $this->jalaliDay()],
            'segment' => [
                Rule::requiredIf($widget === 'rfm_segment'),
                Rule::in([...array_map(fn (RfmSegment $s) => $s->value, RfmSegment::cases()), 'none']),
            ],
            'level' => [
                Rule::requiredIf($widget === 'churn_level'),
                Rule::in([...array_map(fn (ChurnRiskLevel $l) => $l->value, ChurnRiskLevel::cases()), 'none']),
            ],
            'cohort_month' => [
                Rule::requiredIf($widget === 'cohort_period'),
                'string', 'regex:/^\d{4}-\d{2}$/',
            ],
            'period_number' => [
                Rule::requiredIf($widget === 'cohort_period'),
                'integer', 'min:0',
            ],
            'affinity_level' => [
                Rule::requiredIf($widget === 'affinity_pair'),
                Rule::in(['product', 'category', 'variation']),
            ],
            'entity_a_id' => [
                Rule::requiredIf($widget === 'affinity_pair'),
                'integer', 'min:1',
            ],
            'entity_b_id' => [
                Rule::requiredIf($widget === 'affinity_pair'),
                'integer', 'min:1',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->any() || ! $this->filled('from') || ! $this->filled('to')) {
                return;
            }

            $from = JalaliDay::start((string) $this->input('from'));
            $to = JalaliDay::start((string) $this->input('to'));

            if ($from === null || $to === null) {
                return;
            }

            if ($from->greaterThan($to)) {
                $validator->errors()->add('to', 'انتهای بازه نباید قبل از ابتدای آن باشد.');

                return;
            }

            if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
                $validator->errors()->add('to', 'بازه انتخابی بیش از حد بزرگ است.');
            }
        });
    }

    public function widget(): string
    {
        return (string) $this->route('widget');
    }

    public function actor(): User
    {
        $user = $this->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }

    /** @return array<string, string> */
    public function widgetParams(): array
    {
        return array_filter([
            'segment' => $this->filled('segment') ? (string) $this->input('segment') : null,
            'level' => $this->filled('level') ? (string) $this->input('level') : null,
            'cohort_month' => $this->filled('cohort_month') ? (string) $this->input('cohort_month') : null,
            'period_number' => $this->filled('period_number') ? (string) $this->input('period_number') : null,
            'affinity_level' => $this->filled('affinity_level') ? (string) $this->input('affinity_level') : null,
            'entity_a_id' => $this->filled('entity_a_id') ? (string) $this->input('entity_a_id') : null,
            'entity_b_id' => $this->filled('entity_b_id') ? (string) $this->input('entity_b_id') : null,
        ], fn (?string $value) => $value !== null);
    }

    public function period(): DashboardPeriod
    {
        $from = $this->filled('from') ? JalaliDay::start((string) $this->input('from')) : null;
        $to = $this->filled('to') ? JalaliDay::start((string) $this->input('to')) : null;

        if ($from !== null && $to !== null) {
            return DashboardPeriod::fromDates(
                CarbonImmutable::parse($from->setTimezone('Asia/Tehran')->toDateString()),
                CarbonImmutable::parse($to->setTimezone('Asia/Tehran')->toDateString()),
            );
        }

        return DashboardPeriod::lastDays(self::DEFAULT_DAYS, CarbonImmutable::now());
    }

    private function jalaliDay(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (JalaliDay::start((string) $value) === null) {
                $fail("مقدار {$attribute} باید یک تاریخ شمسی معتبر (YYYY/MM/DD) باشد.");
            }
        };
    }
}
