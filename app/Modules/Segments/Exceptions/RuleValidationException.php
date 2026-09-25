<?php

declare(strict_types=1);

namespace App\Modules\Segments\Exceptions;

use RuntimeException;

/**
 * A Segments rule (PRD §17 JSON Rule Schema) failed validation before ever reaching RuleCompiler
 * (P5-03). Messages are Persian because RuleValidator's caller is the Rule Builder UI (D10: Persian
 * RTL only) — unlike RuleWhitelistException (P5-01), which is an internal defense-in-depth guard the
 * UI should never normally trigger. `reason` is a stable machine code for the same failure.
 */
final class RuleValidationException extends RuntimeException
{
    public const INVALID_STRUCTURE = 'invalid_structure';

    public const DEPTH_EXCEEDED = 'depth_exceeded';

    public const TOO_MANY_NODES = 'too_many_nodes';

    public const INVALID_GROUP_OPERATOR = 'invalid_group_operator';

    public const INVALID_CHILDREN_COUNT = 'invalid_children_count';

    public const INVALID_FIELD = 'invalid_field';

    public const INVALID_OPERATOR = 'invalid_operator';

    public const OPERATOR_NOT_ALLOWED_FOR_FIELD = 'operator_not_allowed_for_field';

    public const INVALID_VALUE_SHAPE = 'invalid_value_shape';

    public const VALUE_LIST_TOO_LARGE = 'value_list_too_large';

    public const INVALID_UNIT = 'invalid_unit';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function invalidStructure(): self
    {
        return new self(self::INVALID_STRUCTURE, 'ساختار قانون نامعتبر است: هر گره باید یک Group (op/children) یا یک Condition (field/operator) باشد.');
    }

    public static function depthExceeded(int $max): self
    {
        return new self(self::DEPTH_EXCEEDED, "عمق تودرتویی قانون از حداکثر مجاز ({$max}) بیشتر است.");
    }

    public static function tooManyNodes(int $max): self
    {
        return new self(self::TOO_MANY_NODES, "تعداد کل گره‌های قانون از حداکثر مجاز ({$max}) بیشتر است.");
    }

    public static function invalidGroupOperator(string $op): self
    {
        return new self(self::INVALID_GROUP_OPERATOR, "عملگر گروه '{$op}' نامعتبر است؛ فقط AND یا OR مجاز است.");
    }

    public static function invalidChildrenCount(int $min, int $max): self
    {
        return new self(self::INVALID_CHILDREN_COUNT, "تعداد فرزندان گروه باید بین {$min} تا {$max} باشد.");
    }

    public static function invalidField(string $field): self
    {
        return new self(self::INVALID_FIELD, "فیلد '{$field}' مجاز نیست.");
    }

    public static function invalidOperator(string $operator): self
    {
        return new self(self::INVALID_OPERATOR, "عملگر '{$operator}' مجاز نیست.");
    }

    public static function operatorNotAllowedForField(string $operator, string $field): self
    {
        return new self(self::OPERATOR_NOT_ALLOWED_FOR_FIELD, "عملگر '{$operator}' برای فیلد '{$field}' مجاز نیست.");
    }

    public static function invalidValueShape(string $field, string $operator): self
    {
        return new self(self::INVALID_VALUE_SHAPE, "مقدار داده‌شده برای فیلد '{$field}' با عملگر '{$operator}' سازگار نیست.");
    }

    public static function valueListTooLarge(int $max): self
    {
        return new self(self::VALUE_LIST_TOO_LARGE, "تعداد مقادیر لیست از حداکثر مجاز ({$max}) بیشتر است.");
    }

    public static function invalidUnit(string $unit): self
    {
        return new self(self::INVALID_UNIT, "واحد '{$unit}' نامعتبر است؛ فقط days یا toman مجاز است.");
    }
}
