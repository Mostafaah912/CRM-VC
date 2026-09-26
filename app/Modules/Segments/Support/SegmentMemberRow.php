<?php

declare(strict_types=1);

namespace App\Modules\Segments\Support;

use App\Support\PhoneMask;
use App\Support\TehranDateTime;
use Carbon\CarbonImmutable;

/**
 * One customer on a segment's member list (P5-06 detail page). Built from a plain `DB::table()` join row
 * (segment_members x customers), never an Eloquent Customer — the phone is ALWAYS masked here, same rule
 * as CustomerListRow: this list has no path that carries a full number, only PhoneRevealButton's audited
 * per-customer endpoint does.
 *
 * @phpstan-type SegmentMemberShape array{id: int, display_name: string|null, phone: string|null, status: string, lifecycle_stage: string, added_at: string}
 */
final readonly class SegmentMemberRow
{
    /** Qualified so the join (segment_members x customers) has no ambiguous column. */
    public const COLUMNS = [
        'customers.id', 'customers.phone_normalized', 'customers.display_name',
        'customers.status', 'customers.lifecycle_stage', 'segment_members.added_at',
    ];

    public static function fromRow(\stdClass $row): self
    {
        return new self($row);
    }

    private function __construct(private \stdClass $row) {}

    /** @return SegmentMemberShape */
    public function toArray(): array
    {
        $r = $this->row;

        return [
            'id' => (int) $r->id,
            'display_name' => $r->display_name,
            'phone' => $r->phone_normalized === null ? null : PhoneMask::mask((string) $r->phone_normalized),
            'status' => (string) $r->status,
            'lifecycle_stage' => (string) $r->lifecycle_stage,
            'added_at' => TehranDateTime::format(CarbonImmutable::parse($r->added_at)),
        ];
    }
}
