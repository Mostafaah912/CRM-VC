<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Models\AiInsight;
use App\Support\JalaliDate;
use App\Support\TehranDateTime;
use Carbon\CarbonImmutable;

/**
 * An ai_insights row as the screen gets it. Two rules: (1) an EXPLICIT whitelist — the page receives the model's answer fields and a few
 * display facts, never the raw payload, a prompt, a context or a cache key; (2) cost, tokens, model and prompt version appear only when the
 * viewer holds ai.view_cost. All text is passed through as plain strings (the UI renders text only, never HTML).
 */
final class AnalystInsightPresenter
{
    /**
     * @param  string|null  $asOfIso  the newest complete data day now; an insight about an earlier day is "outdated"
     * @return array<string, mixed>
     */
    public function present(AiInsight $row, bool $withCost, ?string $asOfIso): array
    {
        $p = $row->payload;
        $meta = is_array($p['meta'] ?? null) ? $p['meta'] : [];
        $adjustment = $this->confidenceAdjustment($meta);

        $view = [
            'id' => $row->id,
            'type' => $row->type,
            'scope' => $row->scope,
            'period' => [
                'start' => $row->period_start === null ? null : JalaliDate::format(CarbonImmutable::parse((string) $row->period_start, 'Asia/Tehran')),
                'end' => $row->period_end === null ? null : JalaliDate::format(CarbonImmutable::parse((string) $row->period_end, 'Asia/Tehran')),
            ],
            'created_at' => TehranDateTime::format($row->created_at),
            'manual' => $row->requested_by !== null,
            'outdated' => $asOfIso !== null && $row->period_end !== null && CarbonImmutable::parse((string) $row->period_end)->toDateString() < $asOfIso,
            'confidence' => $this->str($p['confidence'] ?? null),
            'confidence_adjustment' => $adjustment,
            'ungrounded_numbers' => array_values(array_map('strval', array_filter((array) ($meta['ungrounded_numbers'] ?? []), 'is_scalar'))),
            'headline' => $this->str($p['headline'] ?? null),
            'summary' => $this->str($p['summary'] ?? null),
            'key_metrics' => $this->rows($p['key_metrics'] ?? [], ['label', 'value', 'change']),
            'insights' => $this->rows($p['insights'] ?? [], ['text', 'kind', 'severity']),
            'risks' => $this->rows($p['risks'] ?? [], ['text', 'severity']),
            'opportunities' => $this->rows($p['opportunities'] ?? [], ['text']),
            'recommendations' => $this->rows($p['recommendations'] ?? [], ['action', 'rationale', 'effort', 'priority']),
            'anomalies_explained' => $this->rows($p['anomalies_explained'] ?? [], ['interpretation']),
            'data_quality_note' => $this->str($p['data_quality_note'] ?? null),
            'data_gaps' => array_values(array_map('strval', array_filter((array) ($p['data_gaps'] ?? []), 'is_scalar'))),
        ];

        if ($withCost) {
            $view['technical'] = [
                'provider' => $row->provider,
                'model' => $row->model,
                'prompt_version' => $row->prompt_version,
                'input_tokens' => $row->input_tokens,
                'output_tokens' => $row->output_tokens,
                'cost_usd' => $row->cost_usd,
                'duration_ms' => $row->duration_ms,
            ];
        }

        return $view;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{from: string, to: string, reasons: list<string>}|null
     */
    private function confidenceAdjustment(array $meta): ?array
    {
        foreach ((array) ($meta['adjustments'] ?? []) as $a) {
            if (is_array($a) && ($a['field'] ?? null) === 'confidence') {
                return ['from' => $this->str($a['from'] ?? null), 'to' => $this->str($a['to'] ?? null), 'reasons' => array_values(array_map('strval', array_filter((array) ($a['reasons'] ?? []), 'is_string')))];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $fields
     * @return list<array<string, string>>
     */
    private function rows(mixed $list, array $fields): array
    {
        $out = [];

        foreach (is_array($list) ? $list : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $row = [];

            foreach ($fields as $f) {
                $row[$f] = $this->str($item[$f] ?? null);
            }

            $out[] = $row;
        }

        return $out;
    }

    private function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }
}
