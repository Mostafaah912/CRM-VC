<?php

declare(strict_types=1);

namespace App\Modules\Ai\Analyst;

/**
 * The structured answer the analyst must return. It is a SUPERSET of PRD §19's output schema
 * `{ headline, summary, key_metrics[], insights[{text,kind,severity}], risks[], opportunities[], recommendations[{action,rationale,effort}],
 * data_gaps[], confidence }` — every PRD field keeps its name — plus the additions this task asked for: `metric_refs` (grounding paths
 * into the context), `anomalies_explained`, `priority` and `data_quality_note`. Only the JSON-Schema subset Gemini accepts is sent:
 * type / properties / enum / required / items / description. Size limits are NOT sent (they are not in that subset); the validator
 * enforces them from limits().
 */
final class OutputSchema
{
    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $refs = ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Dotted paths into the data block that back this statement, e.g. kpis.windows.0.result.change_percent.net_revenue.'];
        $severity = ['type' => 'string', 'enum' => ['info', 'warning', 'critical']];

        return [
            'type' => 'object',
            'properties' => [
                'headline' => ['type' => 'string', 'description' => 'One sentence: what changed most. Max 120 characters.'],
                'summary' => ['type' => 'string', 'description' => 'A short plain-Persian paragraph. Max 700 characters.'],
                'key_metrics' => ['type' => 'array', 'description' => 'The most important numbers, quoted from the data, never computed.', 'items' => [
                    'type' => 'object',
                    'properties' => ['label' => ['type' => 'string'], 'value' => ['type' => 'string'], 'change' => ['type' => 'string'], 'metric_refs' => $refs],
                    'required' => ['label', 'value', 'change', 'metric_refs'],
                ]],
                'insights' => ['type' => 'array', 'description' => 'Findings. kind: fact = direct number, inference = a hypothesis, recommendation = a suggestion.', 'items' => [
                    'type' => 'object',
                    'properties' => ['text' => ['type' => 'string'], 'kind' => ['type' => 'string', 'enum' => ['fact', 'inference', 'recommendation']], 'severity' => $severity, 'metric_refs' => $refs],
                    'required' => ['text', 'kind', 'severity', 'metric_refs'],
                ]],
                'risks' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['text' => ['type' => 'string'], 'severity' => $severity, 'metric_refs' => $refs],
                    'required' => ['text', 'severity', 'metric_refs'],
                ]],
                'opportunities' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['text' => ['type' => 'string'], 'metric_refs' => $refs],
                    'required' => ['text', 'metric_refs'],
                ]],
                'recommendations' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => ['type' => 'string'], 'rationale' => ['type' => 'string'],
                        'effort' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']], 'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                        'metric_refs' => $refs,
                    ],
                    'required' => ['action', 'rationale', 'effort', 'priority', 'metric_refs'],
                ]],
                'anomalies_explained' => ['type' => 'array', 'description' => 'One entry per anomaly in the data block that matters. ref = path of that anomaly, e.g. anomalies.anomalies.0.', 'items' => [
                    'type' => 'object',
                    'properties' => ['ref' => ['type' => 'string'], 'interpretation' => ['type' => 'string'], 'is_data_issue' => ['type' => 'boolean']],
                    'required' => ['ref', 'interpretation', 'is_data_issue'],
                ]],
                'data_gaps' => ['type' => 'array', 'description' => 'What the data could not answer.', 'items' => ['type' => 'string']],
                'data_quality_note' => ['type' => 'string', 'description' => 'Plain statement of stale data or suspected data gaps; empty string when the data is healthy.'],
                'confidence' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
            ],
            'required' => ['headline', 'summary', 'key_metrics', 'insights', 'risks', 'opportunities', 'recommendations', 'anomalies_explained', 'data_gaps', 'data_quality_note', 'confidence'],
        ];
    }

    /**
     * Limits by schema path (numeric segments written as `*`). maxLength counts characters, maxItems counts list entries.
     *
     * @return array<string, array{maxLength?: int, maxItems?: int}>
     */
    public static function limits(): array
    {
        return [
            'headline' => ['maxLength' => 120],
            'summary' => ['maxLength' => 700],
            'key_metrics' => ['maxItems' => 8],
            'key_metrics.*.label' => ['maxLength' => 80], 'key_metrics.*.value' => ['maxLength' => 80], 'key_metrics.*.change' => ['maxLength' => 80],
            'key_metrics.*.metric_refs' => ['maxItems' => 6],
            'insights' => ['maxItems' => 8], 'insights.*.text' => ['maxLength' => 400], 'insights.*.metric_refs' => ['maxItems' => 6],
            'risks' => ['maxItems' => 5], 'risks.*.text' => ['maxLength' => 400], 'risks.*.metric_refs' => ['maxItems' => 6],
            'opportunities' => ['maxItems' => 5], 'opportunities.*.text' => ['maxLength' => 400], 'opportunities.*.metric_refs' => ['maxItems' => 6],
            'recommendations' => ['maxItems' => 5], 'recommendations.*.action' => ['maxLength' => 300], 'recommendations.*.rationale' => ['maxLength' => 400], 'recommendations.*.metric_refs' => ['maxItems' => 6],
            'anomalies_explained' => ['maxItems' => 10], 'anomalies_explained.*.interpretation' => ['maxLength' => 400], 'anomalies_explained.*.ref' => ['maxLength' => 120],
            'data_gaps' => ['maxItems' => 5], 'data_gaps.*' => ['maxLength' => 300],
            'data_quality_note' => ['maxLength' => 500],
        ];
    }
}
