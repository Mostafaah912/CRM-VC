<?php

declare(strict_types=1);

namespace App\Modules\Ai\Dto;

/**
 * `status`: ok (something was evaluated), insufficient_data (no day had enough same-weekday history),
 * low_volume (history exists but every comparison was skipped as too small to judge), no_data (no complete day at all).
 */
final readonly class AnomalyReport
{
    /** @param list<AnomalyEvent> $events sorted: severity, then newest, then metric */
    public function __construct(
        public string $status,
        public ?string $asOf,
        public ?int $dataAgeDays,
        public int $windowDays,
        public int $evaluated,
        public int $lowVolumeSkips,
        public array $events,
    ) {}
}
