<?php

declare(strict_types=1);

namespace App\Modules\Segments\Exceptions;

use RuntimeException;

/**
 * A rule condition named a field or operator outside PRD §17's whitelist. Thrown, never silently
 * ignored or passed through — the whole point of the whitelist is that an unknown field can never
 * reach the query builder as a column name (CLAUDE.md §3 SQL-injection rule for the Segments module).
 */
final class RuleWhitelistException extends RuntimeException
{
    public const INVALID_FIELD = 'invalid_field';

    public const INVALID_OPERATOR = 'invalid_operator';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function invalidField(string $field): self
    {
        return new self(self::INVALID_FIELD, "Field '{$field}' is not in the Segments rule whitelist.");
    }

    public static function invalidOperator(string $operator): self
    {
        return new self(self::INVALID_OPERATOR, "Operator '{$operator}' is not in the Segments rule whitelist.");
    }
}
