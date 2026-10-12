<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

/** Placeholder until P7-05: reports "no detector" instead of inventing anomalies. */
final class NullAnomalySource implements AnomalySource
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function recent(int $days): array
    {
        return [];
    }
}
