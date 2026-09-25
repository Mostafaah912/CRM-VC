<?php

declare(strict_types=1);

namespace App\Modules\Segments\Support;

use App\Modules\Segments\Models\Segment;
use App\Support\TehranDateTime;

/**
 * A segment as the P5-06 detail page shows it (PRD §25: "مشخصات، خلاصه‌ی rule, ..."). `rule` is the raw
 * PRD §17 JSON tree — this task renders it as read-only formatted JSON, not a natural-language sentence;
 * PRD names no rendering format for the "summary", and a full field/operator-label sentence builder is
 * out of scope here (P5-06 named default, ARCHITECTURE.md).
 *
 * @phpstan-type SegmentDetailShape array{id: int, name: string, description: string|null, type: string, rule: array<mixed>|null, member_count: int, last_evaluated_at: string|null, last_eval_ms: int|null, is_active: bool, is_system: bool}
 */
final readonly class SegmentDetail
{
    public static function fromModel(Segment $segment): self
    {
        return new self($segment);
    }

    private function __construct(private Segment $segment) {}

    /** @return SegmentDetailShape */
    public function toArray(): array
    {
        $s = $this->segment;

        return [
            'id' => $s->id,
            'name' => $s->name,
            'description' => $s->description,
            'type' => $s->type->value,
            'rule' => $s->rule,
            'member_count' => $s->member_count,
            'last_evaluated_at' => TehranDateTime::formatOrNull($s->last_evaluated_at),
            'last_eval_ms' => $s->last_eval_ms,
            'is_active' => $s->is_active,
            'is_system' => $s->is_system,
        ];
    }
}
