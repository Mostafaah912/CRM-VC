<?php

declare(strict_types=1);

namespace App\Modules\Segments\Support;

use App\Modules\Segments\Enums\RuleOperator;
use App\Modules\Segments\Services\RuleValidator;

/**
 * Serializes the P5-01 whitelist (fields + operators) and the P5-02 structural limits into the
 * exact shape the RuleBuilder React component (P5-05) expects as an Inertia prop. Persian labels
 * live only here — nowhere else hardcodes a field/operator name, and the frontend never
 * hand-maintains its own copy of the whitelist (CLAUDE.md §2: no handwritten whitelist in the UI).
 */
final class RuleWhitelistPresenter
{
    /** @var array<string, string> field name => Persian label */
    private const FIELD_LABELS = [
        'province' => 'استان',
        'city' => 'شهر',
        'status' => 'وضعیت',
        'lifecycle_stage' => 'مرحله‌ی چرخه‌ی عمر',
        'first_seen_at' => 'تاریخ اولین مشاهده',
        'recency_days' => 'روزهای گذشته از آخرین خرید',
        'total_orders' => 'تعداد سفارش',
        'total_revenue' => 'مجموع درآمد',
        'monetary' => 'ارزش پولی',
        'aov' => 'میانگین ارزش سفارش',
        'frequency' => 'تعداد خرید',
        'r_score' => 'امتیاز تازگی (R)',
        'f_score' => 'امتیاز تکرار (F)',
        'm_score' => 'امتیاز ارزش (M)',
        'rfm_segment' => 'سگمنت RFM',
        'churn_risk_level' => 'سطح ریسک ریزش',
        'churn_risk_score' => 'امتیاز ریسک ریزش',
        'clv_historical' => 'ارزش طول عمر تاریخی',
        'purchase_cycle_days' => 'چرخه‌ی خرید (روز)',
        'expected_next_order_at' => 'تاریخ تخمینی خرید بعدی',
        'cohort_month' => 'ماه کوهورت',
        'product' => 'محصول',
        'category' => 'دسته‌بندی',
        'variation' => 'گونه',
        'segment' => 'سگمنت',
    ];

    /** @var array<string, string> RuleOperator::value => Persian label */
    private const OPERATOR_LABELS = [
        '=' => 'برابر است با',
        '!=' => 'برابر نیست با',
        '>' => 'بزرگتر از',
        '>=' => 'بزرگتر یا مساوی',
        '<' => 'کوچکتر از',
        '<=' => 'کوچکتر یا مساوی',
        'in' => 'شامل یکی از',
        'not_in' => 'شامل هیچ‌کدام نیست',
        'between' => 'بین',
        'is_null' => 'خالی است',
        'is_not_null' => 'خالی نیست',
        'contains' => 'شامل می‌شود',
        'bought_product' => 'این محصول را خریده',
        'not_bought_product' => 'این محصول را نخریده',
        'bought_category' => 'از این دسته خریده',
        'not_bought_category' => 'از این دسته نخریده',
        'bought_variation' => 'این گونه را خریده',
        'in_segment' => 'عضو این سگمنت است',
        'not_in_segment' => 'عضو این سگمنت نیست',
        'within_days_of_now' => 'در بازه‌ی ± روز از امروز',
    ];

    /** @return array<string, mixed> */
    public static function toArray(): array
    {
        return [
            'fields' => self::fields(),
            'operators' => self::operators(),
            'limits' => [
                'maxDepth' => RuleValidator::MAX_DEPTH,
                'maxNodes' => RuleValidator::MAX_NODES,
                'minChildren' => RuleValidator::MIN_CHILDREN,
                'maxChildren' => RuleValidator::MAX_CHILDREN,
                'maxListValues' => RuleValidator::MAX_LIST_VALUES,
                'maxRelativeDays' => RuleValidator::RELATIVE_DATE_MAX_DAYS,
            ],
        ];
    }

    /** @return list<array{name: string, group: string, label: string}> */
    private static function fields(): array
    {
        return array_map(
            static fn (string $name): array => [
                'name' => $name,
                'group' => RuleFieldWhitelist::group($name)->value,
                'label' => self::FIELD_LABELS[$name],
            ],
            [
                ...RuleFieldWhitelist::CUSTOMER_FIELDS,
                ...RuleFieldWhitelist::METRICS_FIELDS,
                ...RuleFieldWhitelist::BEHAVIOR_FIELDS,
            ],
        );
    }

    /** @return list<array{name: string, label: string, behaviorOnly: bool, valueShape: string}> */
    private static function operators(): array
    {
        return array_map(
            static fn (RuleOperator $operator): array => [
                'name' => $operator->value,
                'label' => self::OPERATOR_LABELS[$operator->value],
                'behaviorOnly' => in_array($operator, RuleValidator::BEHAVIOR_OPERATORS, true),
                'valueShape' => match (true) {
                    in_array($operator, RuleValidator::LIST_OPERATORS, true) => 'list',
                    $operator === RuleOperator::Between => 'range',
                    in_array($operator, RuleValidator::NULL_OPERATORS, true) => 'none',
                    in_array($operator, RuleValidator::RELATIVE_DATE_OPERATORS, true) => 'relative_days',
                    default => 'scalar',
                },
            ],
            RuleOperator::cases(),
        );
    }
}
