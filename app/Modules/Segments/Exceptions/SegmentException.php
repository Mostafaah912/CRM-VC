<?php

declare(strict_types=1);

namespace App\Modules\Segments\Exceptions;

use App\Modules\Segments\Enums\SegmentType;
use RuntimeException;

/** SegmentService (P5-04) refused an operation — evaluate/preview/export each have exactly one way to fail here. */
final class SegmentException extends RuntimeException
{
    public const NOT_DYNAMIC = 'not_dynamic';

    public const PREVIEW_TIMED_OUT = 'preview_timed_out';

    public const EXPORT_FORBIDDEN = 'export_forbidden';

    public const IS_SYSTEM = 'is_system';

    public const NAME_TAKEN = 'name_taken';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** Only a `dynamic` segment has a rule to evaluate; `static`/`manual` membership is never rule-derived. */
    public static function notDynamic(SegmentType $type): self
    {
        return new self(self::NOT_DYNAMIC, "فقط سگمنت‌های پویا (dynamic) قابل ارزیابی‌اند؛ این سگمنت از نوع «{$type->value}» است.");
    }

    /** Postgres cancelled the preview query after the 5s statement_timeout (PRD §17) — never a bare 500. */
    public static function previewTimedOut(): self
    {
        return new self(self::PREVIEW_TIMED_OUT, 'محاسبه‌ی پیش‌نمایش این قانون بیش از حد طول کشید. قانون را ساده‌تر کنید و دوباره تلاش کنید.');
    }

    public static function exportForbidden(): self
    {
        return new self(self::EXPORT_FORBIDDEN, 'شما مجوز خروجی‌گیری از مشتریان را ندارید.');
    }

    /** P5-06: a Seed segment (P5-07) may never be edited or deleted (PRD §17's 12 seed segments). */
    public static function isSystem(): self
    {
        return new self(self::IS_SYSTEM, 'سگمنت‌های سیستمی قابل ویرایش یا حذف نیستند.');
    }

    /** Backstop for the `segments_name_unique` (lower(name)) index — the FormRequest already checks this; this covers the race. */
    public static function nameTaken(): self
    {
        return new self(self::NAME_TAKEN, 'این نام قبلاً برای سگمنت دیگری استفاده شده است.');
    }
}
