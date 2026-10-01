<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Modules\Analytics\Support\DashboardPeriod;
use App\Modules\Customers\Support\JalaliDay;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The dashboard's optional period filter (PRD §18: "فیلتر بازه"): `from`/`to` are Jalali days
 * (YYYY/MM/DD or YYYY-MM-DD, ASCII or Persian digits — JalaliDay's own format), both given together or
 * neither. Missing both defaults to the last 30 days ending today (Asia/Tehran).
 */
final class DashboardRequest extends FormRequest
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
        return [
            'from' => ['nullable', 'string', 'required_with:to', $this->jalaliDay()],
            'to' => ['nullable', 'string', 'required_with:from', $this->jalaliDay()],
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

    /**
     * The filters as typed, for the form to keep its values.
     *
     * @return array{from: string|null, to: string|null}
     */
    public function echo(): array
    {
        return [
            'from' => $this->filled('from') ? (string) $this->input('from') : null,
            'to' => $this->filled('to') ? (string) $this->input('to') : null,
        ];
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
