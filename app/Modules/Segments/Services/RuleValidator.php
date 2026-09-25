<?php

declare(strict_types=1);

namespace App\Modules\Segments\Services;

use App\Modules\Segments\Enums\RuleFieldGroup;
use App\Modules\Segments\Enums\RuleOperator;
use App\Modules\Segments\Exceptions\RuleValidationException;
use App\Modules\Segments\Exceptions\RuleWhitelistException;
use App\Modules\Segments\Support\RuleFieldWhitelist;

/**
 * Validates a PRD §17 JSON Rule Schema (Group|Condition) before RuleCompiler (P5-03) ever turns it
 * into a query. Structure, limits (depth <= 4, nodes <= 100, children 1..20, list values <= 200) and
 * the P5-01 field/operator whitelist are all checked here — RuleCompiler can then trust its input
 * completely and never needs its own field/operator branch for "unknown" (CLAUDE.md §3).
 */
final class RuleValidator
{
    private const MAX_DEPTH = 4;

    private const MAX_NODES = 100;

    private const MIN_CHILDREN = 1;

    private const MAX_CHILDREN = 20;

    private const MAX_LIST_VALUES = 200;

    /** Operators only meaningful on a `behavior` field (PRD §17); every other operator is for `customer`/`metrics` fields. */
    private const BEHAVIOR_OPERATORS = [
        RuleOperator::BoughtProduct, RuleOperator::NotBoughtProduct,
        RuleOperator::BoughtCategory, RuleOperator::NotBoughtCategory,
        RuleOperator::BoughtVariation, RuleOperator::InSegment, RuleOperator::NotInSegment,
    ];

    /** Operators whose value must be an array, capped at MAX_LIST_VALUES (PRD §17: `"in" array <= 200`). */
    private const LIST_OPERATORS = [
        RuleOperator::In, RuleOperator::NotIn, RuleOperator::InSegment, RuleOperator::NotInSegment,
    ];

    private const NULL_OPERATORS = [RuleOperator::IsNull, RuleOperator::IsNotNull];

    private const VALID_UNITS = ['days', 'toman'];

    /** @param array<mixed> $rule @throws RuleValidationException */
    public static function validate(array $rule): void
    {
        $nodeCount = 0;

        self::validateNode($rule, groupDepth: 0, nodeCount: $nodeCount);
    }

    /** @param array<mixed> $node */
    private static function validateNode(array $node, int $groupDepth, int &$nodeCount): void
    {
        $nodeCount++;

        if ($nodeCount > self::MAX_NODES) {
            throw RuleValidationException::tooManyNodes(self::MAX_NODES);
        }

        if (array_key_exists('op', $node) && array_key_exists('children', $node)) {
            self::validateGroup($node, $groupDepth, $nodeCount);

            return;
        }

        if (array_key_exists('field', $node) && array_key_exists('operator', $node)) {
            self::validateCondition($node);

            return;
        }

        throw RuleValidationException::invalidStructure();
    }

    /** @param array<mixed> $group */
    private static function validateGroup(array $group, int $groupDepth, int &$nodeCount): void
    {
        $newDepth = $groupDepth + 1;

        if ($newDepth > self::MAX_DEPTH) {
            throw RuleValidationException::depthExceeded(self::MAX_DEPTH);
        }

        if (! in_array($group['op'], ['AND', 'OR'], true)) {
            throw RuleValidationException::invalidGroupOperator(is_string($group['op']) ? $group['op'] : gettype($group['op']));
        }

        if (! is_array($group['children'])) {
            throw RuleValidationException::invalidStructure();
        }

        $count = count($group['children']);

        if ($count < self::MIN_CHILDREN || $count > self::MAX_CHILDREN) {
            throw RuleValidationException::invalidChildrenCount(self::MIN_CHILDREN, self::MAX_CHILDREN);
        }

        foreach ($group['children'] as $child) {
            if (! is_array($child)) {
                throw RuleValidationException::invalidStructure();
            }

            self::validateNode($child, $newDepth, $nodeCount);
        }
    }

    /** @param array<mixed> $condition */
    private static function validateCondition(array $condition): void
    {
        if (! is_string($condition['field']) || ! is_string($condition['operator'])) {
            throw RuleValidationException::invalidStructure();
        }

        $field = $condition['field'];

        try {
            $group = RuleFieldWhitelist::group($field);
        } catch (RuleWhitelistException) {
            throw RuleValidationException::invalidField($field);
        }

        try {
            $operator = RuleOperator::fromWhitelist($condition['operator']);
        } catch (RuleWhitelistException) {
            throw RuleValidationException::invalidOperator($condition['operator']);
        }

        $isBehaviorOperator = in_array($operator, self::BEHAVIOR_OPERATORS, true);

        if (($group === RuleFieldGroup::Behavior) !== $isBehaviorOperator) {
            throw RuleValidationException::operatorNotAllowedForField($operator->value, $field);
        }

        self::validateValueShape($condition, $field, $operator);

        if (array_key_exists('unit', $condition) && ! in_array($condition['unit'], self::VALID_UNITS, true)) {
            throw RuleValidationException::invalidUnit(is_string($condition['unit']) ? $condition['unit'] : gettype($condition['unit']));
        }
    }

    /** @param array<mixed> $condition */
    private static function validateValueShape(array $condition, string $field, RuleOperator $operator): void
    {
        $hasValue = array_key_exists('value', $condition);

        if (in_array($operator, self::NULL_OPERATORS, true)) {
            if ($hasValue) {
                throw RuleValidationException::invalidValueShape($field, $operator->value);
            }

            return;
        }

        if (! $hasValue) {
            throw RuleValidationException::invalidValueShape($field, $operator->value);
        }

        $value = $condition['value'];

        if (in_array($operator, self::LIST_OPERATORS, true)) {
            if (! is_array($value)) {
                throw RuleValidationException::invalidValueShape($field, $operator->value);
            }

            if (count($value) > self::MAX_LIST_VALUES) {
                throw RuleValidationException::valueListTooLarge(self::MAX_LIST_VALUES);
            }

            return;
        }

        if ($operator === RuleOperator::Between) {
            if (! is_array($value) || count($value) !== 2) {
                throw RuleValidationException::invalidValueShape($field, $operator->value);
            }

            return;
        }

        if (is_array($value)) {
            throw RuleValidationException::invalidValueShape($field, $operator->value);
        }
    }
}
