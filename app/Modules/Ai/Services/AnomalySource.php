<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

/**
 * Where get_anomalies reads from. P7-05's AnomalyDetector (pure statistics, no LLM) implements it; until then the
 * container gives NullAnomalySource. Items are plain arrays — the tool keeps only a whitelisted set of scalar fields.
 */
interface AnomalySource
{
    public function isAvailable(): bool;

    /** @return list<array<string, mixed>> */
    public function recent(int $days): array;
}
