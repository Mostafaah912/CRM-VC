<?php

declare(strict_types=1);

namespace App\Support\Rules;

use App\Support\JalaliDay;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The one validation rule every Jalali `YYYY/MM/DD` text field in the app shares (P6-14 phase 2) —
 * previously duplicated, with two of its three copies failing in English (CLAUDE.md §2: Persian UI
 * text only). Delegates the actual parse to `JalaliDay::start()`, the same single source every
 * `*Request::filters()`/`period()` method already converts through.
 */
final class JalaliDayRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || JalaliDay::start($value) === null) {
            $fail("مقدار {$attribute} باید یک تاریخ شمسی معتبر به‌صورت YYYY/MM/DD باشد.");
        }
    }
}
