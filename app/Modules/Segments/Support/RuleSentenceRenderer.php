<?php

declare(strict_types=1);

namespace App\Modules\Segments\Support;

use App\Modules\Segments\Enums\RuleOperator;

/**
 * Renders a PRD §17 JSON Rule Schema (Group|Condition) as one plain Persian sentence — no JSON, no
 * English key, for the metrics guide page's 12 system segments (P6-18 phase 5: "بدون JSON/کلید
 * انگلیسی"). Field and operator labels come from `RuleWhitelistPresenter::toArray()` — the one
 * existing source the Rule Builder UI already reads, never a second hand-copied list. Enum VALUE
 * labels (rfm_segment, churn_risk_level, status, lifecycle_stage) mirror `resources/js/lib/
 * customer-labels.ts` by hand — the same "two tables, kept in sync by hand" convention already
 * established for `DrillService::COLUMN_LABELS`/`drill-labels.ts` (ARCHITECTURE.md, "P6-12").
 *
 * Scope: every field/operator combination the 12 real seed segments (`DefaultSegmentSeeder`) use —
 * scalar/list/range/null/relative-day conditions on customer+metrics fields, and AND/OR groups.
 * Behavior operators (`bought_product` etc.) fall back to showing the raw id: none of the 12 seeds
 * use one, so resolving a product/category/segment NAME here would pull Catalog/Segments services
 * into a module that otherwise needs none — deliberately not built until a real segment needs it.
 */
final class RuleSentenceRenderer
{
    /** @var array<string, array<string, string>> field => (stored value => Persian label), mirroring resources/js/lib/customer-labels.ts */
    private const VALUE_LABELS = [
        'rfm_segment' => [
            'champion' => 'قهرمان',
            'loyal' => 'وفادار',
            'promising' => 'امیدبخش',
            'new_customer' => 'مشتری جدید',
            'at_risk' => 'در معرض ریزش',
            'cant_lose' => 'نباید از دست برود',
            'hibernating' => 'رخوت‌زده',
            'lost' => 'ازدست‌رفته',
        ],
        'churn_risk_level' => [
            'low' => 'پایدار',
            'medium' => 'ریسک متوسط',
            'high' => 'ریسک بالا',
            'lost' => 'ازدست‌رفته',
        ],
        'status' => [
            'active' => 'فعال',
            'blocked' => 'مسدود',
            'anonymized' => 'ناشناس‌شده',
        ],
        'lifecycle_stage' => [
            'prospect' => 'بالقوه',
            'new' => 'جدید',
            'active' => 'فعال',
            'repeat' => 'تکرارشونده',
            'loyal' => 'وفادار',
            'at_risk' => 'در معرض ریزش',
            'dormant' => 'خفته',
            'lost' => 'ازدست‌رفته',
        ],
    ];

    /** @var array<string, string>|null lazily built from RuleWhitelistPresenter::toArray() */
    private static ?array $fieldLabels = null;

    /** @var array<string, string>|null */
    private static ?array $operatorLabels = null;

    /** @param array<mixed> $rule */
    public function render(array $rule): string
    {
        return $this->renderNode($rule);
    }

    /** @param array<mixed> $node */
    private function renderNode(array $node): string
    {
        if (array_key_exists('op', $node) && array_key_exists('children', $node)) {
            $conjunction = $node['op'] === 'AND' ? ' و ' : ' یا ';
            $parts = array_map(fn (array $child): string => $this->renderNode($child), $node['children']);

            return count($parts) === 1 ? $parts[0] : implode($conjunction, array_map(fn (string $p): string => "({$p})", $parts));
        }

        return $this->renderCondition($node);
    }

    /** @param array<mixed> $condition */
    private function renderCondition(array $condition): string
    {
        $field = (string) $condition['field'];
        $operatorValue = (string) $condition['operator'];
        $operator = RuleOperator::tryFrom($operatorValue);
        $value = $condition['value'] ?? null;
        $fieldLabel = $this->fieldLabel($field);

        if ($operator === RuleOperator::IsNull) {
            return "{$fieldLabel} خالی است";
        }

        if ($operator === RuleOperator::IsNotNull) {
            return "{$fieldLabel} خالی نیست";
        }

        if ($operator === RuleOperator::WithinDaysOfNow) {
            return "{$fieldLabel} در بازه‌ی ± {$value} روز از امروز";
        }

        if ($operator === RuleOperator::Between && is_array($value)) {
            return "{$fieldLabel} بین {$this->valueLabel($field, $value[0])} تا {$this->valueLabel($field, $value[1])}";
        }

        if (in_array($operator, [RuleOperator::In, RuleOperator::NotIn], true) && is_array($value)) {
            $labels = array_map(fn (mixed $v): string => $this->valueLabel($field, $v), $value);

            return "{$fieldLabel} ".$this->operatorLabel($operatorValue).': '.implode('، ', $labels);
        }

        $isBehaviorOperator = str_contains($operatorValue, 'bought_') || in_array($operatorValue, ['in_segment', 'not_in_segment'], true);

        if ($isBehaviorOperator) {
            return 'مشتری '.$this->operatorLabel($operatorValue)." (#{$value})";
        }

        return "{$fieldLabel} ".$this->operatorLabel($operatorValue).' '.$this->valueLabel($field, $value);
    }

    private function fieldLabel(string $field): string
    {
        self::$fieldLabels ??= array_column(RuleWhitelistPresenter::toArray()['fields'], 'label', 'name');

        return self::$fieldLabels[$field] ?? $field;
    }

    private function operatorLabel(string $operator): string
    {
        self::$operatorLabels ??= array_column(RuleWhitelistPresenter::toArray()['operators'], 'label', 'name');

        return self::$operatorLabels[$operator] ?? $operator;
    }

    private function valueLabel(string $field, mixed $value): string
    {
        if (isset(self::VALUE_LABELS[$field])) {
            return self::VALUE_LABELS[$field][(string) $value] ?? (string) $value;
        }

        return match (true) {
            is_bool($value) => $value ? 'بله' : 'خیر',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
