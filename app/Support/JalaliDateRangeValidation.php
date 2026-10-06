<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\Validator;

/**
 * The "from must not be after to" (+ optional max-span) check every Jalali range filter in the app
 * repeats inside its own `withValidator()` (P6-14 phase 2) — consolidated here so a Persian message
 * is guaranteed everywhere instead of copy-pasted per `FormRequest` (two of the three existing
 * copies had drifted into English before this).
 */
final class JalaliDateRangeValidation
{
    public static function assertOrder(Validator $validator, string $fromField, string $toField, ?int $maxDays = null): void
    {
        if ($validator->errors()->any()) {
            return;
        }

        $data = $validator->getData();
        $fromRaw = $data[$fromField] ?? null;
        $toRaw = $data[$toField] ?? null;

        if (! is_string($fromRaw) || $fromRaw === '' || ! is_string($toRaw) || $toRaw === '') {
            return;
        }

        $from = JalaliDay::start($fromRaw);
        $to = JalaliDay::start($toRaw);

        if ($from === null || $to === null) {
            return;
        }

        if ($from->greaterThan($to)) {
            $validator->errors()->add($toField, 'انتهای بازه نباید قبل از ابتدای آن باشد.');

            return;
        }

        if ($maxDays !== null && $from->diffInDays($to) + 1 > $maxDays) {
            $validator->errors()->add($toField, 'بازه انتخابی بیش از حد بزرگ است.');
        }
    }
}
