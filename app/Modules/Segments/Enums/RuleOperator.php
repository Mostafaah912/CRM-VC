<?php

declare(strict_types=1);

namespace App\Modules\Segments\Enums;

use App\Modules\Segments\Exceptions\RuleWhitelistException;

/** The `Operator` union type from PRD §17's JSON Rule Schema, exactly — never a raw string in RuleValidator/RuleCompiler. */
enum RuleOperator: string
{
    case Equals = '=';
    case NotEquals = '!=';
    case GreaterThan = '>';
    case GreaterThanOrEqual = '>=';
    case LessThan = '<';
    case LessThanOrEqual = '<=';
    case In = 'in';
    case NotIn = 'not_in';
    case Between = 'between';
    case IsNull = 'is_null';
    case IsNotNull = 'is_not_null';
    case Contains = 'contains';
    case BoughtProduct = 'bought_product';
    case NotBoughtProduct = 'not_bought_product';
    case BoughtCategory = 'bought_category';
    case NotBoughtCategory = 'not_bought_category';
    case BoughtVariation = 'bought_variation';
    case InSegment = 'in_segment';
    case NotInSegment = 'not_in_segment';

    /** Parses a rule's raw operator string against the whitelist. Never silently falls through — an unknown operator throws. */
    public static function fromWhitelist(string $value): self
    {
        return self::tryFrom($value) ?? throw RuleWhitelistException::invalidOperator($value);
    }
}
