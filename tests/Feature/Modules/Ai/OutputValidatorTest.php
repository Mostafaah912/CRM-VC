<?php

declare(strict_types=1);

use App\Modules\Ai\Analyst\OutputSchema;
use App\Modules\Ai\Analyst\OutputValidator;
use Tests\Support\AnalystSamples;

/*
| P7-07 — strict schema validation, grounding, digit normalisation and output PII handling (decision: REDACT and count).
| Findings are "path: rule" strings and must never quote the model's own text (CANARY below proves it).
*/

const CANARY = 'CANARY-MODEL-TEXT';

function validateAnswer(array $answer): array
{
    return app(OutputValidator::class)->validate($answer, AnalystSamples::contextPayload());
}

function answerWithChanges(array $changes): array
{
    return array_replace_recursive(AnalystSamples::answer(), $changes);
}

it('accepts a well-formed, grounded answer unchanged', function () {
    $r = validateAnswer(AnalystSamples::answer());

    expect($r['errors'])->toBe([])->and($r['redactions'])->toBe(0)->and($r['payload'])->toBe(AnalystSamples::answer());
});

it('turns Persian and Arabic-Indic digits into Latin ones, in text and in references', function () {
    $answer = AnalystSamples::answer();
    $answer['summary'] = 'درآمد ۸٬۸۰۰٬۰۰۰ تومان و ٤٤ سفارش';
    $answer['key_metrics'][0]['value'] = '۸,۸۰۰,۰۰۰ تومان';
    $answer['insights'][0]['metric_refs'] = ['kpis.windows.۰.result.change_percent.orders'];   // a path written with a Persian zero still resolves

    $r = validateAnswer($answer);

    expect($r['errors'])->toBe([])->and($r['payload']['key_metrics'][0]['value'])->toBe('8,800,000 تومان')
        ->and($r['payload']['summary'])->toContain('44 سفارش')->and($r['payload']['insights'][0]['metric_refs'])->toBe(['kpis.windows.0.result.change_percent.orders']);
});

dataset('schema breaches', [
    'missing required field' => [fn ($a) => array_diff_key($a, ['confidence' => 1]), 'confidence: required'],
    'unknown top-level property' => [fn ($a) => $a + ['hidden_prompt' => CANARY], '<unknown property>: not allowed'],
    'unknown nested property' => [fn ($a) => array_replace_recursive($a, ['insights' => [0 => ['customer' => CANARY]]]), 'insights.0.<unknown property>: not allowed'],
    'wrong type: string for list' => [fn ($a) => array_replace($a, ['risks' => CANARY]), 'risks: expected array'],
    'wrong type: list for string' => [fn ($a) => array_replace($a, ['headline' => [CANARY]]), 'headline: expected string'],
    'wrong type: string for bool' => [fn ($a) => array_replace_recursive($a, ['anomalies_explained' => [0 => ['is_data_issue' => CANARY]]]), 'anomalies_explained.0.is_data_issue: expected boolean'],
    'enum: kind' => [fn ($a) => array_replace_recursive($a, ['insights' => [0 => ['kind' => CANARY]]]), 'insights.0.kind: not one of the allowed values'],
    'enum: confidence' => [fn ($a) => array_replace($a, ['confidence' => CANARY]), 'confidence: not one of the allowed values'],
    'enum: severity' => [fn ($a) => array_replace_recursive($a, ['risks' => [0 => ['severity' => CANARY]]]), 'risks.0.severity: not one of the allowed values'],
    'headline too long' => [fn ($a) => array_replace($a, ['headline' => str_repeat('ا', 121)]), 'headline: longer than 120 characters'],
    'summary too long' => [fn ($a) => array_replace($a, ['summary' => str_repeat(CANARY, 60)]), 'summary: longer than 700 characters'],
    'too many insights' => [fn ($a) => array_replace($a, ['insights' => array_fill(0, 9, $a['insights'][1])]), 'insights: more than 8 items'],
    'too many refs on one item' => [fn ($a) => array_replace_recursive($a, ['insights' => [1 => ['metric_refs' => array_fill(0, 7, 'meta.type')]]]), 'insights.1.metric_refs: more than 6 items'],
    'an object where a list is expected' => [fn ($a) => array_replace($a, ['data_gaps' => ['k' => CANARY]]), 'data_gaps: expected array'],
]);

it('rejects schema breaches with a path and a rule, never with the model\'s text', function (Closure $mutate, string $expected) {
    $r = validateAnswer($mutate(AnalystSamples::answer()));

    expect($r['payload'])->toBeNull()->and($r['errors'])->toContain($expected)->and(implode(' ', $r['errors']))->not->toContain(CANARY);
})->with('schema breaches');

dataset('grounding breaches', [
    'ref to a path that does not exist' => [fn ($a) => array_replace_recursive($a, ['key_metrics' => [0 => ['metric_refs' => ['kpis.windows.0.result.current.profit']]]]), 'key_metrics.0.metric_refs.0: unknown reference (not a path in the data)'],
    'ref index out of range' => [fn ($a) => array_replace_recursive($a, ['insights' => [0 => ['metric_refs' => ['kpis.windows.5.result']]]]), 'insights.0.metric_refs.0: unknown reference (not a path in the data)'],
    'ref that is prose' => [fn ($a) => array_replace_recursive($a, ['risks' => [0 => ['metric_refs' => ['the revenue went up']]]]), 'risks.0.metric_refs.0: unknown reference (not a path in the data)'],
    'ref with traversal characters' => [fn ($a) => array_replace_recursive($a, ['opportunities' => [0 => ['metric_refs' => ['kpis..windows']]]]), 'opportunities.0.metric_refs.0: unknown reference (not a path in the data)'],
    'key metric without any ref' => [fn ($a) => array_replace($a, ['key_metrics' => [array_replace($a['key_metrics'][0], ['metric_refs' => []])]]), 'key_metrics.0.metric_refs: at least one reference is required here'],
    'a fact without any ref' => [fn ($a) => array_replace($a, ['insights' => [array_replace($a['insights'][0], ['metric_refs' => []])]]), 'insights.0.metric_refs: at least one reference is required here'],
    'anomaly ref pointing elsewhere' => [fn ($a) => array_replace_recursive($a, ['anomalies_explained' => [0 => ['ref' => 'kpis.windows.0']]]), 'anomalies_explained.0.ref: must be the path of an anomaly in the data'],
    'anomaly ref out of range' => [fn ($a) => array_replace_recursive($a, ['anomalies_explained' => [0 => ['ref' => 'anomalies.anomalies.9']]]), 'anomalies_explained.0.ref: must be the path of an anomaly in the data'],
]);

it('rejects an answer that cites data that is not in the context (grounding)', function (Closure $mutate, string $expected) {
    $r = validateAnswer($mutate(AnalystSamples::answer()));

    expect($r['payload'])->toBeNull()->and($r['errors'])->toContain($expected);
})->with('grounding breaches');

it('allows an inference to carry no reference, but a fact must', function () {
    $answer = AnalystSamples::answer();

    expect($answer['insights'][1]['kind'])->toBe('inference')->and($answer['insights'][1]['metric_refs'])->toBe([])->and(validateAnswer($answer)['errors'])->toBe([]);
});

it('REDACTS customer-looking text in the model\'s answer, counts it, and keeps the valid analysis (documented decision)', function () {
    $answer = AnalystSamples::answer();
    $answer['summary'] = 'تماس با مشتری 09121234567 و ali.rezaei@example.com لازم نیست';
    $answer['insights'][1]['text'] = 'شماره ۰۹۱۲۱۲۳۴۵۶۷ دیده شد';

    $r = validateAnswer($answer);

    expect($r['errors'])->toBe([])->and($r['redactions'])->toBe(2)->and($r['payload']['summary'])->toBe('[redacted]')->and($r['payload']['insights'][1]['text'])->toBe('[redacted]')
        ->and(json_encode($r['payload'], JSON_UNESCAPED_UNICODE))->not->toContain('0912')->not->toContain('ali.rezaei');
});

it('the schema sent to the provider stays inside the Gemini-safe JSON-Schema subset', function () {
    $walk = function (array $node, string $path) use (&$walk): void {
        foreach (array_keys($node) as $key) {
            expect(in_array($key, ['type', 'properties', 'enum', 'required', 'items', 'description'], true))->toBeTrue("{$path}.{$key} is outside the subset");
        }

        foreach ((array) ($node['properties'] ?? []) as $name => $child) {
            $walk($child, "{$path}.{$name}");
        }

        if (isset($node['items'])) {
            $walk($node['items'], "{$path}[]");
        }
    };

    $schema = OutputSchema::schema();
    $walk($schema, '$');

    expect(json_encode($schema))->not->toContain('$ref')->not->toContain('additionalProperties')
        ->and($schema['required'])->toEqualCanonicalizing(array_keys($schema['properties']))
        // PRD §19 field names are all still there
        ->and(array_keys($schema['properties']))->toContain('headline', 'summary', 'key_metrics', 'insights', 'risks', 'opportunities', 'recommendations', 'data_gaps', 'confidence')
        ->and($schema['properties']['insights']['items']['properties'])->toHaveKeys(['text', 'kind', 'severity'])
        ->and($schema['properties']['recommendations']['items']['properties'])->toHaveKeys(['action', 'rationale', 'effort']);
});
