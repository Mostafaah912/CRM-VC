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

describe('numeric reconciliation of key_metrics.value with the number at its first metric_ref (P7-08)', function () {
    // The sample context: current.net_revenue = 8800000, current.orders = 44, change_percent.net_revenue = 10.0, change_percent.orders = 10.0
    function metricCase(string $ref, string $value): array
    {
        $answer = AnalystSamples::answer();
        $answer['key_metrics'] = [['label' => 'x', 'value' => $value, 'change' => '', 'metric_refs' => [$ref, 'kpis.windows.0.result.current.orders']]];
        $context = AnalystSamples::contextPayload();
        $context['kpis']['windows'][0]['result']['change_percent']['refunds'] = -12.5;
        $context['kpis']['windows'][0]['result']['current']['label'] = 'text';

        return app(OutputValidator::class)->validate($answer, $context);
    }

    it('accepts the same number in the formats a Persian answer uses', function (string $ref, string $value) {
        expect(metricCase($ref, $value)['errors'])->toBe([]);
    })->with([
        'latin thousands separators + unit' => ['kpis.windows.0.result.current.net_revenue', '8,800,000 تومان'],
        'no separator' => ['kpis.windows.0.result.current.net_revenue', '8800000'],
        'persian digits + arabic thousands separator' => ['kpis.windows.0.result.current.net_revenue', '۸٬۸۰۰٬۰۰۰ تومان'],
        'spaces as separators' => ['kpis.windows.0.result.current.net_revenue', '8 800 000'],
        'count' => ['kpis.windows.0.result.current.orders', '44 سفارش'],
        'percent with sign and one decimal' => ['kpis.windows.0.result.change_percent.net_revenue', '+10.0%'],
        'percent rounded to an integer' => ['kpis.windows.0.result.change_percent.net_revenue', '10%'],
        'negative percent, signed' => ['kpis.windows.0.result.change_percent.refunds', '-12.5%'],
        'negative percent as the unsigned size of a drop' => ['kpis.windows.0.result.change_percent.refunds', '12.5% کاهش'],
        'negative percent rounded to the stated precision' => ['kpis.windows.0.result.change_percent.refunds', '-12%'],
        'negative percent, one decimal rounding' => ['kpis.windows.0.result.change_percent.refunds', '12.5'],
    ]);

    it('rejects a number that is not the one at the reference', function (string $ref, string $value) {
        $r = metricCase($ref, $value);

        expect($r['payload'])->toBeNull()->and($r['errors'])->toContain('key_metrics.0.value: does not match the number at its first reference');
    })->with([
        'money off by one' => ['kpis.windows.0.result.current.net_revenue', '8,800,001 تومان'],
        'money in millions (a rounding the prompt forbids)' => ['kpis.windows.0.result.current.net_revenue', '8.8'],
        'wrong count' => ['kpis.windows.0.result.current.orders', '45'],
        'percent rounded the wrong way' => ['kpis.windows.0.result.change_percent.net_revenue', '11%'],
        'percent more precise than the truth allows' => ['kpis.windows.0.result.change_percent.net_revenue', '10.4%'],
        'sign flipped on a signed statement' => ['kpis.windows.0.result.change_percent.refunds', '+12.5%'],
        'another metric\'s number' => ['kpis.windows.0.result.current.net_revenue', '44'],
    ]);

    it('rejects a value with no number at all, but does not compare a reference that is not a number', function () {
        $none = metricCase('kpis.windows.0.result.current.net_revenue', 'بالا');
        $text = metricCase('kpis.windows.0.result.current.label', 'هر چیزی');

        expect($none['errors'])->toContain('key_metrics.0.value: must state the number found at its first reference')->and($text['errors'])->toBe([]);
    });

    it('checks only the FIRST reference', function () {
        $answer = AnalystSamples::answer();
        $answer['key_metrics'][0]['value'] = '44';
        $answer['key_metrics'][0]['metric_refs'] = ['kpis.windows.0.result.current.orders', 'kpis.windows.0.result.current.net_revenue'];

        expect(app(OutputValidator::class)->validate($answer, AnalystSamples::contextPayload())['errors'])->toBe([]);
    });
});

/*
| Confidence cap (first real run: the model said `high` on a partial-day comparison). Enforced in code, recorded in payload.meta.
*/
function cappedBy(array $context, array $answerChanges = []): array
{
    return app(OutputValidator::class)->validate(array_replace(AnalystSamples::answer(), ['confidence' => 'high'], $answerChanges), $context)['payload'];
}

it('keeps high when the evidence supports it, and adds no meta', function () {
    $p = cappedBy(AnalystSamples::contextPayload());

    expect($p['confidence'])->toBe('high')->and($p)->not->toHaveKey('meta');
});

it('never lets a medium or low answer rise', function () {
    $r = app(OutputValidator::class)->validate(array_replace(AnalystSamples::answer(), ['confidence' => 'low']), array_replace_recursive(AnalystSamples::contextPayload(), ['data_quality' => ['warnings' => ['stale_data']]]));

    expect($r['payload']['confidence'])->toBe('low')->and($r['payload'])->not->toHaveKey('meta');
});

it('downgrades high to medium when most customers have low CLV confidence, and records why', function () {
    $c = AnalystSamples::contextPayload();
    $c['customer_health']['customer_base']['clv_confidence'] = [['level' => 'high', 'customers' => 10], ['level' => 'low', 'customers' => 30]];
    $p = cappedBy($c);

    expect($p['confidence'])->toBe('medium')->and($p['meta']['adjustments'])->toBe([['field' => 'confidence', 'from' => 'high', 'to' => 'medium', 'reasons' => ['clv_confidence_mostly_low']]]);
});

it('does not downgrade when low CLV confidence is only a minority (exactly half is not "most")', function () {
    $c = AnalystSamples::contextPayload();
    $c['customer_health']['customer_base']['clv_confidence'] = [['level' => 'high', 'customers' => 20], ['level' => 'low', 'customers' => 20]];

    expect(cappedBy($c)['confidence'])->toBe('high');
});

it('downgrades high to medium when an anomaly in the data is left unexplained', function () {
    $p = cappedBy(AnalystSamples::contextPayload(), ['anomalies_explained' => []]);

    expect($p['confidence'])->toBe('medium')->and($p['meta']['adjustments'][0]['reasons'])->toBe(['anomaly_unexplained']);
});

it('downgrades high to medium on any data_quality warning, including period_anchor_mismatch', function () {
    $c = array_replace_recursive(AnalystSamples::contextPayload(), ['data_quality' => ['warnings' => ['period_anchor_mismatch']]]);
    $p = cappedBy($c);

    expect($p['confidence'])->toBe('medium')->and($p['meta']['adjustments'][0]['reasons'])->toBe(['data_quality_warnings']);
});

it('lists every reason when several apply', function () {
    $c = array_replace_recursive(AnalystSamples::contextPayload(), ['data_quality' => ['warnings' => ['stale_data']]]);
    $c['customer_health']['customer_base']['clv_confidence'] = [['level' => 'low', 'customers' => 9]];

    expect(cappedBy($c, ['anomalies_explained' => []])['meta']['adjustments'][0]['reasons'])->toBe(['clv_confidence_mostly_low', 'anomaly_unexplained', 'data_quality_warnings']);
});

/*
| P7-08b — key_metrics.change reconciliation, recommendations grounding, and the SOFT ungrounded-number check.
*/
function withKeyMetric(array $overrides): array
{
    $answer = AnalystSamples::answer();
    $answer['key_metrics'][0] = array_replace($answer['key_metrics'][0], $overrides);

    return $answer;
}

it('accepts a change that equals the change_percent in the data, signed or absolute, to the stated precision', function (string $change) {
    expect(validateAnswer(withKeyMetric(['change' => $change]))['errors'])->toBe([]);
})->with(['+10.0%', '+10%', '+10.00%', ' +10.0 % ']);

it('rejects a change that does not match the data (change_percent here is 10.0)', function (string $change) {
    $r = validateAnswer(withKeyMetric(['change' => $change]));

    expect($r['payload'])->toBeNull()->and($r['errors'])->toBe(['key_metrics.0.change: does not match the change_percent in the data']);
})->with(['+12.5%', '-10.0%', '+11%', '+10.1%']);

it('requires change to be a signed percent: a bare number, a missing sign or words around it are rejected', function (string $change) {
    $r = validateAnswer(withKeyMetric(['change' => $change]));

    expect($r['payload'])->toBeNull()->and($r['errors'])->toBe(['key_metrics.0.change: write it as a signed percent such as +12.5% or -3.1% (or leave it empty)']);
})->with(['10.0', '10%', '20.57', 'رشد +10.0 درصد', '+10.0 درصد']);

it('computes the change from the current/previous pair beside the first ref when no change_percent ref is given', function () {
    $ok = validateAnswer(withKeyMetric(['metric_refs' => ['kpis.windows.0.result.current.net_revenue'], 'change' => '+10.0%']));
    $bad = validateAnswer(withKeyMetric(['metric_refs' => ['kpis.windows.0.result.current.net_revenue'], 'change' => '+25%']));

    expect($ok['errors'])->toBe([])->and($bad['errors'])->toBe(['key_metrics.0.change: does not match the change_percent in the data']);
});

it('rejects a change that has a number but nothing in the data to verify it, and allows an empty or non-numeric one', function () {
    $unverifiable = validateAnswer(withKeyMetric(['metric_refs' => ['kpis.windows.0.result.previous.net_revenue'], 'value' => '8,000,000 تومان', 'change' => '+10%']));

    expect($unverifiable['errors'])->toBe(['key_metrics.0.change: states a number but the data has no change_percent (or current/previous pair) to verify it; leave it empty'])
        ->and(validateAnswer(withKeyMetric(['metric_refs' => ['kpis.windows.0.result.previous.net_revenue'], 'value' => '8,000,000', 'change' => '']))['errors'])->toBe([])
        ->and(validateAnswer(withKeyMetric(['metric_refs' => ['kpis.windows.0.result.previous.net_revenue'], 'value' => '8,000,000', 'change' => '—']))['errors'])->toBe([]);
});

it('requires every recommendation to carry grounded metric_refs, like a fact', function () {
    $none = AnalystSamples::answer();
    $none['recommendations'][0]['metric_refs'] = [];
    $bogus = AnalystSamples::answer();
    $bogus['recommendations'][0]['metric_refs'] = ['kpis.windows.0.result.nope'];
    $missing = AnalystSamples::answer();
    unset($missing['recommendations'][0]['metric_refs']);

    expect(validateAnswer($none)['errors'])->toBe(['recommendations.0.metric_refs: at least one reference is required here'])
        ->and(validateAnswer($bogus)['errors'])->toBe(['recommendations.0.metric_refs.0: unknown reference (not a path in the data)'])
        ->and(validateAnswer($missing)['errors'])->toBe(['recommendations.0.metric_refs: required']);
});

it('records numbers in free text that exist nowhere in the data, and caps confidence — it never rejects', function () {
    $answer = AnalystSamples::answer();
    $answer['confidence'] = 'high';
    $answer['summary'] = 'درآمد 8,800,000 تومان بود و 37.5 درصد مشتریان بازگشتند.';
    $answer['insights'][1]['text'] = 'حدود 88 درصد سفارش‌ها تکراری است.';
    $r = validateAnswer($answer);

    expect($r['errors'])->toBe([])->and($r['payload']['meta']['ungrounded_numbers'])->toBe(['37.5', '88'])
        ->and($r['payload']['confidence'])->toBe('medium')->and($r['payload']['meta']['adjustments'][0]['reasons'])->toBe(['ungrounded_numbers']);
});

it('does not flag numbers the data states: exact, absolute value, digits in dates, rounded or scaled (million) forms, thousands separators', function () {
    $answer = AnalystSamples::answer();
    $answer['summary'] = 'درآمد 8,800,000 تومان یعنی 8.8 میلیون تومان؛ روز 1405/07/21 (2026-10-13)، حدود 44 سفارش از 40 و 10 درصد رشد، حدود 130 سفارش در ناهنجاری و 2 سفارش یا بیشتر.';
    $answer['risks'][0]['text'] = 'مقدار مورد انتظار 43 بود (کمتر از 130)؛ 77 درصد.';
    $r = validateAnswer($answer);

    expect($r['payload']['meta']['ungrounded_numbers'] ?? [])->toBe(['77']);   // 77 is the only invented one
});

it('keeps confidence as given and adds no meta when every free-text number is grounded', function () {
    $r = validateAnswer(AnalystSamples::answer());

    expect($r['payload'])->not->toHaveKey('meta');
});
