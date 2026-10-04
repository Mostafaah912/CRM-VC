<?php

declare(strict_types=1);

namespace App\Modules\Segments\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Segments\Enums\RuleFieldGroup;
use App\Modules\Segments\Enums\RuleOperator;
use App\Modules\Segments\Exceptions\RuleWhitelistException;
use App\Modules\Segments\Support\RuleFieldWhitelist;
use App\Support\JalaliDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * PRD §17: `compile(rule): Builder base = Customer::query()->join(customer_metrics)->whereNull(deleted_at)`.
 * The absolute rule this class exists to enforce: a column name is ALWAYS read from the P5-01
 * whitelist, NEVER built from the rule's own strings; every value is ALWAYS a query-builder
 * parameter binding, never concatenated. `RuleValidator::validate()` runs first — a caller can
 * never reach column resolution with a structurally invalid rule — and every field/operator is
 * still independently re-resolved through the whitelist here, so this class stays safe even if
 * called directly, bypassing the validator (GATE 3, PRD §26).
 */
final class RuleCompiler
{
    /** @var array<string, string> field => fully-qualified column, for the 21 non-behavior fields. */
    private const COLUMN_MAP = [
        'province' => 'customers.province',
        'city' => 'customers.city',
        'status' => 'customers.status',
        'lifecycle_stage' => 'customers.lifecycle_stage',
        'first_seen_at' => 'customers.first_seen_at',
        'recency_days' => 'customer_metrics.recency_days',
        'total_orders' => 'customer_metrics.total_orders',
        'total_revenue' => 'customer_metrics.total_revenue',
        'monetary' => 'customer_metrics.monetary',
        'aov' => 'customer_metrics.aov',
        'frequency' => 'customer_metrics.frequency',
        'r_score' => 'customer_metrics.r_score',
        'f_score' => 'customer_metrics.f_score',
        'm_score' => 'customer_metrics.m_score',
        'rfm_segment' => 'customer_metrics.rfm_segment',
        'churn_risk_level' => 'customer_metrics.churn_risk_level',
        'churn_risk_score' => 'customer_metrics.churn_risk_score',
        'clv_historical' => 'customer_metrics.clv_historical',
        'purchase_cycle_days' => 'customer_metrics.purchase_cycle_days',
        'expected_next_order_at' => 'customer_metrics.expected_next_order_at',
        'cohort_month' => 'customer_metrics.cohort_month',
    ];

    /**
     * @param  array<mixed>  $rule
     * @return Builder<Customer>
     */
    public static function compile(array $rule): Builder
    {
        RuleValidator::validate($rule);

        $query = Customer::query()
            ->join('customer_metrics', 'customer_metrics.customer_id', '=', 'customers.id')
            ->whereNull('customers.deleted_at');

        self::applyNode($query, $rule, 'and');

        return $query;
    }

    /**
     * @param  Builder<Customer>  $query
     * @param  array<mixed>  $node
     */
    private static function applyNode(Builder $query, array $node, string $boolean): void
    {
        if (array_key_exists('op', $node) && array_key_exists('children', $node)) {
            $childBoolean = $node['op'] === 'AND' ? 'and' : 'or';

            $query->where(
                /** @param Builder<Customer> $nested */
                function (Builder $nested) use ($node, $childBoolean): void {
                    foreach ($node['children'] as $child) {
                        self::applyNode($nested, $child, $childBoolean);
                    }
                },
                null, null, $boolean,
            );

            return;
        }

        self::applyCondition($query, $node, $boolean);
    }

    /**
     * @param  Builder<Customer>  $query
     * @param  array<mixed>  $condition
     */
    private static function applyCondition(Builder $query, array $condition, string $boolean): void
    {
        $field = $condition['field'];
        $rawOperator = $condition['operator'];

        if (! is_string($field) || ! is_string($rawOperator)) {
            throw RuleWhitelistException::invalidField(is_string($field) ? $field : gettype($field));
        }

        $group = RuleFieldWhitelist::group($field);
        $operator = RuleOperator::fromWhitelist($rawOperator);
        $value = $condition['value'] ?? null;

        if ($group === RuleFieldGroup::Behavior) {
            self::applyBehaviorCondition($query, $operator, $value, $boolean);

            return;
        }

        if ($operator === RuleOperator::WithinDaysOfNow && ! in_array($field, RuleFieldWhitelist::DATE_FIELDS, true)) {
            // Defense in depth (GATE 3): RuleValidator already blocks this combination; re-checked here
            // so RuleCompiler stays safe even called directly, same promise as every other field/operator
            // resolution in this class.
            throw RuleWhitelistException::invalidOperator($operator->value);
        }

        $column = self::COLUMN_MAP[$field] ?? throw RuleWhitelistException::invalidField($field);

        if (in_array($field, RuleFieldWhitelist::DATE_FIELDS, true) && self::isAbsoluteDateOperator($operator)) {
            self::applyDateCondition($query, $column, $operator, $value, $boolean);

            return;
        }

        match ($operator) {
            RuleOperator::Equals => $query->where($column, '=', $value, $boolean),
            RuleOperator::NotEquals => $query->where($column, '!=', $value, $boolean),
            RuleOperator::GreaterThan => $query->where($column, '>', $value, $boolean),
            RuleOperator::GreaterThanOrEqual => $query->where($column, '>=', $value, $boolean),
            RuleOperator::LessThan => $query->where($column, '<', $value, $boolean),
            RuleOperator::LessThanOrEqual => $query->where($column, '<=', $value, $boolean),
            RuleOperator::Contains => $query->where($column, 'like', '%'.$value.'%', $boolean),
            RuleOperator::In => $query->whereIn($column, $value, $boolean),
            RuleOperator::NotIn => $query->whereIn($column, $value, $boolean, true),
            RuleOperator::Between => $query->whereBetween($column, $value, $boolean),
            RuleOperator::IsNull => $query->whereNull($column, $boolean),
            RuleOperator::IsNotNull => $query->whereNull($column, $boolean, true),
            RuleOperator::WithinDaysOfNow => self::applyWithinDaysOfNow($query, $column, $value, $boolean),
            RuleOperator::BoughtProduct, RuleOperator::NotBoughtProduct,
            RuleOperator::BoughtCategory, RuleOperator::NotBoughtCategory,
            RuleOperator::BoughtVariation, RuleOperator::InSegment, RuleOperator::NotInSegment => throw RuleWhitelistException::invalidOperator($operator->value),
        };
    }

    /**
     * PRD §17 seed segment "سررسید خرید مجدد" (P5-07b): `value` is an integer day count N, recomputed
     * fresh from the real current instant on every compile — [now - N days, now + N days] is never
     * stored, so the window always slides with "today" instead of going stale like a stored absolute
     * date range would. `CarbonImmutable::now()` (not a hardcoded `now()` call) so `Carbon::setTestNow()`
     * makes this deterministic in tests. Bound as ordinary `whereBetween` parameters — no raw SQL.
     *
     * @param  Builder<Customer>  $query
     */
    private static function applyWithinDaysOfNow(Builder $query, string $column, mixed $value, string $boolean): void
    {
        $days = (int) $value;
        $now = CarbonImmutable::now();

        $query->whereBetween($column, [$now->subDays($days), $now->addDays($days)], $boolean);
    }

    private static function isAbsoluteDateOperator(RuleOperator $operator): bool
    {
        return in_array($operator, [
            RuleOperator::Equals, RuleOperator::NotEquals,
            RuleOperator::GreaterThan, RuleOperator::GreaterThanOrEqual,
            RuleOperator::LessThan, RuleOperator::LessThanOrEqual,
            RuleOperator::Between,
        ], true);
    }

    /**
     * A DATE_FIELDS condition's `value` is always a Jalali day string `YYYY/MM/DD` (RuleValidator
     * already rejected anything else) — never bound to SQL as-is. Every operator here is translated to
     * a Tehran day-boundary comparison via `JalaliDay::start()`/`nextStart()` (the same pair every
     * other from/to filter in the app uses), so "`first_seen_at <= 1404/01/01`" means "any time up to
     * and including that whole Tehran day", not "before Jalali-day-string midnight UTC".
     *
     * @param  Builder<Customer>  $query
     */
    private static function applyDateCondition(Builder $query, string $column, RuleOperator $operator, mixed $value, string $boolean): void
    {
        match ($operator) {
            RuleOperator::Equals => self::whereJalaliInterval($query, $column, self::jalaliStart((string) $value), self::jalaliNextStart((string) $value), $boolean, not: false),
            RuleOperator::NotEquals => self::whereJalaliInterval($query, $column, self::jalaliStart((string) $value), self::jalaliNextStart((string) $value), $boolean, not: true),
            RuleOperator::GreaterThan => $query->where($column, '>=', self::jalaliNextStart((string) $value), $boolean),
            RuleOperator::GreaterThanOrEqual => $query->where($column, '>=', self::jalaliStart((string) $value), $boolean),
            RuleOperator::LessThan => $query->where($column, '<', self::jalaliStart((string) $value), $boolean),
            RuleOperator::LessThanOrEqual => $query->where($column, '<', self::jalaliNextStart((string) $value), $boolean),
            RuleOperator::Between => self::applyDateBetween($query, $column, $value, $boolean),
            default => throw RuleWhitelistException::invalidOperator($operator->value),
        };
    }

    /** @param Builder<Customer> $query */
    private static function applyDateBetween(Builder $query, string $column, mixed $value, string $boolean): void
    {
        if (! is_array($value) || count($value) !== 2) {
            throw RuleWhitelistException::invalidOperator(RuleOperator::Between->value);
        }

        $starts = [self::jalaliStart((string) $value[0]), self::jalaliStart((string) $value[1])];
        $ends = [self::jalaliNextStart((string) $value[0]), self::jalaliNextStart((string) $value[1])];

        // The two picked days are not assumed ordered (a user can drag a range picker either
        // direction), so the interval is the min start .. max end of the two, not value[0]..value[1].
        $start = $starts[0]->lessThanOrEqualTo($starts[1]) ? $starts[0] : $starts[1];
        $end = $ends[0]->greaterThanOrEqualTo($ends[1]) ? $ends[0] : $ends[1];

        self::whereJalaliInterval($query, $column, $start, $end, $boolean, not: false);
    }

    /**
     * `[$start, $end)` is a Tehran day-boundary interval — never a raw SQL fragment, two ordinary
     * `where`/`orWhere` parameter bindings nested in one group so `$boolean` (and/or) still applies
     * to the condition as a whole, the same way `applyNode()`'s own group nesting does above.
     *
     * @param  Builder<Customer>  $query
     */
    private static function whereJalaliInterval(Builder $query, string $column, CarbonImmutable $start, CarbonImmutable $end, string $boolean, bool $not): void
    {
        $query->where(
            /** @param Builder<Customer> $nested */
            function (Builder $nested) use ($column, $start, $end, $not): void {
                if ($not) {
                    $nested->where($column, '<', $start)->orWhere($column, '>=', $end);
                } else {
                    $nested->where($column, '>=', $start)->where($column, '<', $end);
                }
            },
            null, null, $boolean,
        );
    }

    /** `$value` is already whitelist-validated Jalali `YYYY/MM/DD` by `RuleValidator`; re-checked
     * here too (defense in depth, same promise as every other resolution in this class — GATE 3). */
    private static function jalaliStart(string $value): CarbonImmutable
    {
        return JalaliDay::start($value) ?? throw RuleWhitelistException::invalidOperator($value);
    }

    private static function jalaliNextStart(string $value): CarbonImmutable
    {
        return JalaliDay::nextStart($value) ?? throw RuleWhitelistException::invalidOperator($value);
    }

    /** @param Builder<Customer> $query */
    private static function applyBehaviorCondition(Builder $query, RuleOperator $operator, mixed $value, string $boolean): void
    {
        match ($operator) {
            RuleOperator::BoughtProduct => self::whereBoughtItem($query, 'product_id', $value, $boolean, not: false),
            RuleOperator::NotBoughtProduct => self::whereBoughtItem($query, 'product_id', $value, $boolean, not: true),
            RuleOperator::BoughtCategory => self::whereBoughtCategory($query, $value, $boolean, not: false),
            RuleOperator::NotBoughtCategory => self::whereBoughtCategory($query, $value, $boolean, not: true),
            RuleOperator::BoughtVariation => self::whereBoughtItem($query, 'variation_id', $value, $boolean, not: false),
            RuleOperator::InSegment => self::whereInSegment($query, $value, $boolean, not: false),
            RuleOperator::NotInSegment => self::whereInSegment($query, $value, $boolean, not: true),
            RuleOperator::Equals, RuleOperator::NotEquals, RuleOperator::GreaterThan, RuleOperator::GreaterThanOrEqual,
            RuleOperator::LessThan, RuleOperator::LessThanOrEqual, RuleOperator::Contains,
            RuleOperator::In, RuleOperator::NotIn, RuleOperator::Between,
            RuleOperator::IsNull, RuleOperator::IsNotNull, RuleOperator::WithinDaysOfNow => throw RuleWhitelistException::invalidOperator($operator->value),
        };
    }

    /**
     * `$itemColumn` is a fixed literal from this file ('product_id' or 'variation_id'), never derived from the rule.
     *
     * @param  Builder<Customer>  $query
     */
    private static function whereBoughtItem(Builder $query, string $itemColumn, mixed $value, string $boolean, bool $not): void
    {
        $query->whereExists(function (QueryBuilder $sub) use ($itemColumn, $value): void {
            $sub->from('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereColumn('orders.customer_id', 'customers.id')
                ->where('orders.is_realized', true)
                ->whereNull('orders.deleted_at')
                ->where("order_items.{$itemColumn}", $value);
        }, $boolean, $not);
    }

    /** @param Builder<Customer> $query */
    private static function whereBoughtCategory(Builder $query, mixed $value, string $boolean, bool $not): void
    {
        $query->whereExists(function (QueryBuilder $sub) use ($value): void {
            $sub->from('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->join('product_category_product', 'product_category_product.product_id', '=', 'order_items.product_id')
                ->whereColumn('orders.customer_id', 'customers.id')
                ->where('orders.is_realized', true)
                ->whereNull('orders.deleted_at')
                ->where('product_category_product.category_id', $value);
        }, $boolean, $not);
    }

    /** @param Builder<Customer> $query */
    private static function whereInSegment(Builder $query, mixed $value, string $boolean, bool $not): void
    {
        $query->whereExists(function (QueryBuilder $sub) use ($value): void {
            $sub->from('segment_members')
                ->whereColumn('segment_members.customer_id', 'customers.id')
                ->whereIn('segment_members.segment_id', $value);
        }, $boolean, $not);
    }
}
