<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

/**
 * GATE 1 (PRD §26): every month from the first (Mehr 1403) to the last complete Jalali month must be GREEN. It passes only
 * on evidence — a month never reconciled, red or failed keeps it open, and so does having no complete month to judge.
 */
final readonly class GateOneReport
{
    /**
     * @param  list<GateOneMonth>  $months  oldest first
     */
    public function __construct(
        public bool $passed,
        public array $months,
    ) {}

    /** @return list<string> months never reconciled */
    public function missingMonths(): array
    {
        return array_values(array_map(fn (GateOneMonth $m): string => $m->month, array_filter($this->months, fn (GateOneMonth $m): bool => $m->status === null)));
    }

    /** @return list<string> months reconciled but red or failed */
    public function failingMonths(): array
    {
        return array_values(array_map(fn (GateOneMonth $m): string => $m->month, array_filter($this->months, fn (GateOneMonth $m): bool => $m->status !== null && ! $m->isGreen())));
    }
}
