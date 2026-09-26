<?php

declare(strict_types=1);

namespace App\Modules\Segments\Support;

use App\Modules\Segments\Models\Segment;
use App\Support\TehranDateTime;

/**
 * One segment as the P5-06 list page shows it: name, type, member_count, last_evaluated_at (Jalali),
 * is_active, is_system (PRD §25). Never carries `rule` or `description` — the list is a summary, the
 * detail page (P5-06) reads those.
 *
 * @phpstan-type SegmentListShape array{id: int, name: string, type: string, member_count: int, last_evaluated_at: string|null, is_active: bool, is_system: bool}
 */
final readonly class SegmentListRow
{
    public const COLUMNS = ['id', 'name', 'type', 'member_count', 'last_evaluated_at', 'is_active', 'is_system'];

    public static function fromModel(Segment $segment): self
    {
        return new self($segment);
    }

    private function __construct(private Segment $segment) {}

    /** @return SegmentListShape */
    public function toArray(): array
    {
        $s = $this->segment;

        return [
            'id' => $s->id,
            'name' => $s->name,
            'type' => $s->type->value,
            'member_count' => $s->member_count,
            'last_evaluated_at' => TehranDateTime::formatOrNull($s->last_evaluated_at),
            'is_active' => $s->is_active,
            'is_system' => $s->is_system,
        ];
    }
}
