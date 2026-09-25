<?php

declare(strict_types=1);

namespace App\Modules\Segments\Support;

use App\Modules\Segments\Enums\RuleFieldGroup;
use App\Modules\Segments\Exceptions\RuleWhitelistException;

/**
 * The field whitelist from PRD §17, verbatim — nothing added, nothing guessed. RuleCompiler (P5-03)
 * must resolve a rule condition's `field` through this class before it ever becomes a column name;
 * a field is a fixed PHP string here, never built from request input (CLAUDE.md §3).
 */
final class RuleFieldWhitelist
{
    /** @var list<string> customers.* columns */
    public const CUSTOMER_FIELDS = [
        'province', 'city', 'status', 'lifecycle_stage', 'first_seen_at',
    ];

    /** @var list<string> customer_metrics.* columns */
    public const METRICS_FIELDS = [
        'recency_days', 'total_orders', 'total_revenue', 'monetary', 'aov', 'frequency',
        'r_score', 'f_score', 'm_score', 'rfm_segment', 'churn_risk_level', 'churn_risk_score',
        'clv_historical', 'purchase_cycle_days', 'expected_next_order_at', 'cohort_month',
    ];

    /** @var list<string> not real columns — RuleCompiler resolves these via whereExists/whereNotExists subqueries */
    public const BEHAVIOR_FIELDS = [
        'product', 'category', 'variation', 'segment',
    ];

    /** Resolves a field's group, or throws if it is not in the whitelist. Never returns null and never ignores. */
    public static function group(string $field): RuleFieldGroup
    {
        return match (true) {
            in_array($field, self::CUSTOMER_FIELDS, true) => RuleFieldGroup::Customer,
            in_array($field, self::METRICS_FIELDS, true) => RuleFieldGroup::Metrics,
            in_array($field, self::BEHAVIOR_FIELDS, true) => RuleFieldGroup::Behavior,
            default => throw RuleWhitelistException::invalidField($field),
        };
    }

    /** Throws unless `$field` is in the whitelist. */
    public static function assertValid(string $field): void
    {
        self::group($field);
    }
}
