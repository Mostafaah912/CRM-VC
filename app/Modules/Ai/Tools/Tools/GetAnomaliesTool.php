<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools\Tools;

use App\Modules\Ai\Services\AnomalySource;
use App\Modules\Ai\Tools\AiTool;

final class GetAnomaliesTool extends AiTool
{
    private const FIELDS = ['date', 'metric', 'observed', 'expected', 'deviation_percent', 'direction', 'severity', 'description'];

    public function __construct(private readonly AnomalySource $source) {}

    public function name(): string
    {
        return 'get_anomalies';
    }

    public function description(): string
    {
        return 'Statistical anomalies in daily store metrics detected by the AnomalyDetector (pure statistics, no AI) over the last N days: metric, observed vs expected value, deviation, direction and severity.';
    }

    public function properties(): array
    {
        return [
            'days' => ['type' => 'integer', 'description' => 'Look back this many days, 1 to 30. Default 7.'],
            'limit' => ['type' => 'integer', 'description' => 'Anomalies to return, 1 to 20. Default 10.'],
        ];
    }

    public function rules(): array
    {
        return ['days' => 'integer|min:1|max:30', 'limit' => 'integer|min:1|max:20'];
    }

    public function run(array $arguments): array
    {
        if (! $this->source->isAvailable()) {
            return ['detector_available' => false, 'anomalies' => []];
        }

        $items = array_slice($this->source->recent((int) ($arguments['days'] ?? 7)), 0, (int) ($arguments['limit'] ?? 10));

        return [
            'detector_available' => true,
            'anomalies' => array_map(fn (array $a) => array_intersect_key($a, array_flip(self::FIELDS)), $items),
        ];
    }
}
