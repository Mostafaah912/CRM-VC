<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Metrics\Enums\ChurnRiskLevel;
use App\Modules\Metrics\Enums\RfmSegment;
use App\Modules\Segments\Enums\RuleOperator;
use App\Modules\Segments\Enums\SegmentType;
use App\Modules\Segments\Models\Segment;
use App\Modules\Segments\Services\RuleValidator;
use Illuminate\Database\Seeder;

/**
 * PRD §17 "سگمنت‌های Seed" — 11 of the 12 named segments (see the P5-07 note in ARCHITECTURE.md for why
 * the 12th, "سررسید خرید مجدد", is deferred rather than built here). Every `rule` is built from enum
 * values (RfmSegment/ChurnRiskLevel), never a bare string, and validated with RuleValidator before it is
 * ever written — the same rule SegmentService::create() itself enforces.
 *
 * Idempotent by `lower(name)` (matches the `segments_name_unique` index): re-running never creates a
 * duplicate or drifts a segment's definition, and a soft-deleted seed segment is restored rather than
 * silently left gone — these are supposed to always exist. Only the *definition* fields (description,
 * type, rule, rule_version, is_system) are refreshed on a re-run; `member_count`/`last_evaluated_at`/
 * `last_eval_ms` are left alone so re-seeding never wipes evaluation state a future P5-08
 * RebuildAllSegmentsJob run may have produced.
 */
class DefaultSegmentSeeder extends Seeder
{
    /** @return list<array{name: string, description: string, rule: array<mixed>}> */
    public static function definitions(): array
    {
        $eq = RuleOperator::Equals->value;
        $gte = RuleOperator::GreaterThanOrEqual->value;

        return [
            ['name' => 'قهرمانان', 'description' => 'PRD §17 Seed — rfm_segment = champion', 'rule' => ['field' => 'rfm_segment', 'operator' => $eq, 'value' => RfmSegment::Champion->value]],
            ['name' => 'وفادار', 'description' => 'PRD §17 Seed — rfm_segment = loyal', 'rule' => ['field' => 'rfm_segment', 'operator' => $eq, 'value' => RfmSegment::Loyal->value]],
            ['name' => 'نویدبخش', 'description' => 'PRD §17 Seed — rfm_segment = promising', 'rule' => ['field' => 'rfm_segment', 'operator' => $eq, 'value' => RfmSegment::Promising->value]],
            ['name' => 'مشتری جدید', 'description' => 'PRD §17 Seed — rfm_segment = new_customer', 'rule' => ['field' => 'rfm_segment', 'operator' => $eq, 'value' => RfmSegment::NewCustomer->value]],
            // PRD §17 names this seed "(churn medium)" — churn_risk_level, NOT rfm_segment=at_risk (the
            // RFM page's own "در معرض ریزش" label). Followed literally; see the P5-07 note in
            // ARCHITECTURE.md for the naming clash and the dev-DB comparison against both readings.
            ['name' => 'در معرض ریزش', 'description' => 'PRD §17 Seed — churn_risk_level = medium', 'rule' => ['field' => 'churn_risk_level', 'operator' => $eq, 'value' => ChurnRiskLevel::Medium->value]],
            ['name' => 'نباید از دست برود', 'description' => 'PRD §17 Seed — rfm_segment = cant_lose', 'rule' => ['field' => 'rfm_segment', 'operator' => $eq, 'value' => RfmSegment::CantLose->value]],
            ['name' => 'خوابیده', 'description' => 'PRD §17 Seed — rfm_segment = hibernating', 'rule' => ['field' => 'rfm_segment', 'operator' => $eq, 'value' => RfmSegment::Hibernating->value]],
            // PRD §17 names this seed "(churn lost)" — churn_risk_level, NOT rfm_segment=lost (a
            // different value with the same name). Followed literally, same reasoning as above.
            ['name' => 'از دست رفته', 'description' => 'PRD §17 Seed — churn_risk_level = lost', 'rule' => ['field' => 'churn_risk_level', 'operator' => $eq, 'value' => ChurnRiskLevel::Lost->value]],
            ['name' => 'تک‌خرید', 'description' => 'PRD §17 Seed — total_orders = 1', 'rule' => ['field' => 'total_orders', 'operator' => $eq, 'value' => 1]],
            ['name' => 'پرارزش', 'description' => 'PRD §17 Seed — m_score = 5', 'rule' => ['field' => 'm_score', 'operator' => $eq, 'value' => 5]],
            ['name' => 'VIP', 'description' => 'PRD §17 Seed — m_score = 5 AND f_score >= 4', 'rule' => [
                'op' => 'AND',
                'children' => [
                    ['field' => 'm_score', 'operator' => $eq, 'value' => 5],
                    ['field' => 'f_score', 'operator' => $gte, 'value' => 4],
                ],
            ]],
        ];
    }

    public function run(): void
    {
        foreach (self::definitions() as $definition) {
            RuleValidator::validate($definition['rule']);

            $segment = Segment::withTrashed()->where('name', 'ilike', $definition['name'])->first();

            if ($segment === null) {
                Segment::query()->create([
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'type' => SegmentType::Dynamic,
                    'rule' => $definition['rule'],
                    'rule_version' => 1,
                    'is_active' => true,
                    'is_system' => true,
                    // Nullable on purpose: a seeded system segment has no human author, and hardcoding
                    // any specific user id would break on a fresh install where that user may not exist.
                    'created_by' => null,
                ]);

                continue;
            }

            if ($segment->trashed()) {
                $segment->restore();
            }

            $segment->forceFill([
                'description' => $definition['description'],
                'type' => SegmentType::Dynamic,
                'rule' => $definition['rule'],
                'rule_version' => 1,
                'is_system' => true,
            ])->save();
        }
    }
}
