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
use Illuminate\Support\Facades\Log;

/**
 * PRD §17 "سگمنت‌های Seed" — all 12 named segments (P5-07b added the 12th, "سررسید خرید مجدد",
 * once `RuleOperator::WithinDaysOfNow` existed to express its `expected_next_order_at ±7d` rule).
 * Every `rule` is built from enum values (RfmSegment/ChurnRiskLevel), never a bare string, and
 * validated with RuleValidator before it is ever written — the same rule SegmentService::create()
 * itself enforces.
 *
 * Idempotent by `lower(name)` (matches the `segments_name_unique` index): re-running never creates a
 * duplicate or drifts a segment's definition, and a soft-deleted seed segment is restored rather than
 * silently left gone — these are supposed to always exist. Only the *definition* fields (description,
 * type, rule, rule_version, is_system) are refreshed on a re-run; `member_count`/`last_evaluated_at`/
 * `last_eval_ms` are left alone so re-seeding never wipes evaluation state a future P5-08
 * RebuildAllSegmentsJob run may have produced.
 *
 * P5-07b: if a name collides with a segment that already exists and is NOT `is_system` (a user
 * happened to name their own segment the same thing), that row is left completely alone — never
 * overwritten, never flipped to `is_system`. Only rows this seeder itself owns (`is_system = true`,
 * including soft-deleted ones) are ever updated in place.
 */
class DefaultSegmentSeeder extends Seeder
{
    /** @return list<array{name: string, description: string, rule: array<mixed>}> */
    public static function definitions(): array
    {
        $eq = RuleOperator::Equals->value;
        $gte = RuleOperator::GreaterThanOrEqual->value;
        $in = RuleOperator::In->value;
        $withinDays = RuleOperator::WithinDaysOfNow->value;

        return [
            ['name' => 'قهرمانان', 'description' => 'PRD §17 Seed — rfm_segment = champion', 'rule' => ['field' => 'rfm_segment', 'operator' => $eq, 'value' => RfmSegment::Champion->value]],
            ['name' => 'وفادار', 'description' => 'PRD §17 Seed — rfm_segment = loyal', 'rule' => ['field' => 'rfm_segment', 'operator' => $eq, 'value' => RfmSegment::Loyal->value]],
            ['name' => 'نویدبخش', 'description' => 'PRD §17 Seed — rfm_segment = promising', 'rule' => ['field' => 'rfm_segment', 'operator' => $eq, 'value' => RfmSegment::Promising->value]],
            ['name' => 'مشتری جدید', 'description' => 'PRD §17 Seed — rfm_segment = new_customer', 'rule' => ['field' => 'rfm_segment', 'operator' => $eq, 'value' => RfmSegment::NewCustomer->value]],
            // P5-07b decision: churn_risk_level in [medium, high] — not PRD §17's literal "churn medium"
            // alone (P5-07's single-value reading undercounted against the RFM page's own "در معرض
            // ریزش" meaning; see docs/architecture/sprint-5.md, P5-07b, for the dev-DB comparison).
            ['name' => 'در معرض ریزش', 'description' => 'churn_risk_level in [medium, high]', 'rule' => ['field' => 'churn_risk_level', 'operator' => $in, 'value' => [ChurnRiskLevel::Medium->value, ChurnRiskLevel::High->value]]],
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
            ['name' => 'سررسید خرید مجدد', 'description' => 'PRD §17 Seed — expected_next_order_at ± 7 days of today', 'rule' => ['field' => 'expected_next_order_at', 'operator' => $withinDays, 'value' => 7]],
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

            if (! $segment->is_system) {
                // A user already owns a segment with this exact name — never overwrite it or flip it
                // to is_system, even though the name collides with a seed definition. Logged, not
                // $this->command->warn(): the real call site is always app(...)->run() directly
                // (never `db:seed`), where $command is never set and a console warning would be silent.
                Log::warning("DefaultSegmentSeeder: skipped \"{$definition['name']}\" — a non-system segment with this name already exists.", ['segment_id' => $segment->id]);

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
