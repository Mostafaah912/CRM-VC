<?php

declare(strict_types=1);

use App\Modules\Ai\Enums\AiInsightType;
use App\Modules\Analytics\Enums\AffinityLevel;
use App\Modules\Catalog\Enums\CostSource;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Core\Enums\AuditActorType;
use App\Modules\Core\Enums\PermissionEffect;
use App\Modules\Customers\Enums\AddressType;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\IdentityConfidence;
use App\Modules\Customers\Enums\IdentityConflictStatus;
use App\Modules\Customers\Enums\IdentitySource;
use App\Modules\Customers\Enums\LifecycleStage;
use App\Modules\Metrics\Enums\ChurnRiskLevel;
use App\Modules\Metrics\Enums\ClvConfidence;
use App\Modules\Metrics\Enums\MetricRunMode;
use App\Modules\Metrics\Enums\MetricRunStatus;
use App\Modules\Metrics\Enums\RfmSegment;
use App\Modules\Orders\Enums\OrderHistorySource;
use App\Modules\Segments\Enums\SegmentType;
use App\Modules\Sync\Enums\SyncLogLevel;
use App\Modules\Sync\Enums\SyncMode;
use App\Modules\Sync\Enums\SyncStatus;
use Illuminate\Support\Facades\DB;

/**
 * CLAUDE.md §2/§3: "Enums for every status/level/stage" AND "every enum column has a
 * CHECK constraint". This proves the two never drift: each PHP enum's cases must equal
 * the value set its CHECK constraint enforces in the real PostgreSQL schema.
 */
dataset('enum_columns', [
    'customers.status' => [CustomerStatus::class, 'customers', 'status'],
    'customers.lifecycle_stage' => [LifecycleStage::class, 'customers', 'lifecycle_stage'],
    'customer_identities.source' => [IdentitySource::class, 'customer_identities', 'source'],
    'customer_identities.confidence' => [IdentityConfidence::class, 'customer_identities', 'confidence'],
    'identity_conflicts.status' => [IdentityConflictStatus::class, 'identity_conflicts', 'status'],
    'customer_addresses.type' => [AddressType::class, 'customer_addresses', 'type'],
    'products.type' => [ProductType::class, 'products', 'type'],
    'products.status' => [ProductStatus::class, 'products', 'status'],
    'product_variations.status' => [ProductStatus::class, 'product_variations', 'status'],
    'product_costs.source' => [CostSource::class, 'product_costs', 'source'],
    'order_status_history.source' => [OrderHistorySource::class, 'order_status_history', 'source'],
    'permission_overrides.effect' => [PermissionEffect::class, 'permission_overrides', 'effect'],
    'audit_logs.actor_type' => [AuditActorType::class, 'audit_logs', 'actor_type'],
    'metric_runs.mode' => [MetricRunMode::class, 'metric_runs', 'mode'],
    'metric_runs.status' => [MetricRunStatus::class, 'metric_runs', 'status'],
    'customer_metrics.rfm_segment' => [RfmSegment::class, 'customer_metrics', 'rfm_segment'],
    'customer_metrics.clv_confidence' => [ClvConfidence::class, 'customer_metrics', 'clv_confidence'],
    'customer_metrics.churn_risk_level' => [ChurnRiskLevel::class, 'customer_metrics', 'churn_risk_level'],
    'segments.type' => [SegmentType::class, 'segments', 'type'],
    'product_affinities.level' => [AffinityLevel::class, 'product_affinities', 'level'],
    'sync_jobs.mode' => [SyncMode::class, 'sync_jobs', 'mode'],
    'sync_jobs.status' => [SyncStatus::class, 'sync_jobs', 'status'],
    'sync_cursors.last_status' => [SyncStatus::class, 'sync_cursors', 'last_status'],
    'sync_logs.level' => [SyncLogLevel::class, 'sync_logs', 'level'],
    'ai_insights.type' => [AiInsightType::class, 'ai_insights', 'type'],
]);

it('has a PHP enum whose cases equal the CHECK constraint value set', function (string $enum, string $table, string $column) {
    $definition = DB::selectOne(
        "select pg_get_constraintdef(oid) as def from pg_constraint where conrelid = ?::regclass and contype = 'c' and conname = ?",
        [$table, "{$table}_{$column}_check"],
    )?->def;

    expect($definition)->not->toBeNull("{$table}.{$column} has no CHECK constraint");

    // PostgreSQL prints IN ('a', 'b') as 'a'::character varying, but a one-value IN as = 'a'::text.
    preg_match_all("/'([^']+)'::(?:character varying|text)/", (string) $definition, $matches);

    $checkValues = $matches[1];
    $enumValues = array_map(fn (BackedEnum $case) => $case->value, $enum::cases());

    sort($checkValues);
    sort($enumValues);

    expect($enumValues)->toBe($checkValues);
})->with('enum_columns');
