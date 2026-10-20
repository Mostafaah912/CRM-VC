<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools\Tools;

use App\Modules\Ai\Dto\AnomalyEvent;
use App\Modules\Ai\Services\AnomalySource;
use App\Modules\Ai\Tools\AiTool;
use App\Modules\Ai\Tools\ToolFormat;

final class GetAnomaliesTool extends AiTool
{
    public function __construct(private readonly AnomalySource $source) {}

    public function name(): string
    {
        return 'get_anomalies';
    }

    public function description(): string
    {
        return 'Statistical anomalies in complete daily store metrics (orders, net revenue, AOV, new customers, refund rate, repeat share) over the last N days, found by robust same-weekday comparison with no AI: observed vs expected, deviation, robust z, severity (info/warning/critical), direction and an optional known_event label. Consecutive days are one event with a date range. status says whether there was enough history to judge.';
    }

    public function properties(): array
    {
        return [
            'days' => ['type' => 'integer', 'description' => 'Look back this many complete days, 1 to 30. Default 7.'],
            'limit' => ['type' => 'integer', 'description' => 'Events to return, 1 to 20. Default 10.'],
        ];
    }

    public function rules(): array
    {
        return ['days' => 'integer|min:1|max:30', 'limit' => 'integer|min:1|max:20'];
    }

    public function run(array $arguments): array
    {
        $report = $this->source->report((int) ($arguments['days'] ?? 7));
        $events = array_slice($report->events, 0, (int) ($arguments['limit'] ?? 10));

        return [
            'status' => $report->status,
            'as_of' => $report->asOf === null ? null : ToolFormat::date($report->asOf),
            'data_age_days' => $report->dataAgeDays,
            'baseline_window_days' => $report->windowDays,
            'events_total' => count($report->events),
            'anomalies' => array_map(fn (AnomalyEvent $e) => [
                'metric' => $e->metric,
                'unit' => $e->unit,
                'from' => ToolFormat::date($e->from),
                'to' => ToolFormat::date($e->to),
                'days' => $e->days,
                'peak_date' => ToolFormat::date($e->peakDate),
                'observed' => $e->observed,
                'expected' => $e->expected,
                'deviation_percent' => $e->deviationPercent,
                'robust_z' => $e->robustZ,
                'severity' => $e->severity,
                'direction' => $e->direction,
                'known_event' => $e->knownEvent,
            ], $events),
        ];
    }
}
