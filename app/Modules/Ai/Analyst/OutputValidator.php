<?php

declare(strict_types=1);

namespace App\Modules\Ai\Analyst;

use App\Modules\Ai\Context\ContextPiiGuard;
use App\Support\Digits;

/**
 * Strict validation of the model's answer against OutputSchema (no unknown properties, exact types, enums, required, size limits),
 * then GROUNDING: every metric_ref must be a real dotted path in the context the model was given, and an anomalies_explained.ref must
 * point at an anomaly. Persian/Arabic-Indic digits are normalised to Latin first. Finally the text passes the PII guard in REDACT mode
 * (decision: a mobile/email-looking string in model output is replaced with [redacted] and counted, not a reason to throw away an
 * otherwise valid analysis — the context never contained customer data, so such a string is a hallucination or an echoed SKU; the
 * count is returned so P7-08 can surface it). Findings are "path: rule" strings and never quote the model's text.
 */
final class OutputValidator
{
    public function __construct(private readonly ContextPiiGuard $guard) {}

    /**
     * @param  array<string, mixed>  $context  the decoded context the model saw
     * @param  array<array-key, mixed>  $answer
     * @return array{payload: array<string, mixed>|null, errors: list<string>, redactions: int}
     */
    public function validate(array $answer, array $context): array
    {
        $answer = $this->normalizeDigits($answer);
        $errors = [];
        $this->check($answer, OutputSchema::schema(), '', $errors);

        if ($errors === []) {
            $this->ground($answer, $context, $errors);
        }

        if ($errors !== []) {
            return ['payload' => null, 'errors' => array_slice($errors, 0, 12), 'redactions' => 0];
        }

        [$clean, $redactions] = $this->guard->sanitize($answer);
        $clean = $this->capConfidence($clean, $context);

        return ['payload' => $clean, 'errors' => [], 'redactions' => count($redactions)];
    }

    /**
     * The model may not claim `high` when the evidence does not support it — enforced HERE, not trusted to the prompt. high becomes medium when
     * (a) most customers' CLV confidence is low, (b) an anomaly in the data was not explained, or (c) data_quality carries any warning
     * (stale data, failed run, period_anchor_mismatch). The change is recorded in payload.meta.adjustments (never silent).
     *
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function capConfidence(array $answer, array $context): array
    {
        if (($answer['confidence'] ?? null) !== 'high') {
            return $answer;
        }

        $reasons = [];
        $levels = $context['customer_health']['customer_base']['clv_confidence'] ?? [];
        $low = 0;
        $total = 0;

        foreach (is_array($levels) ? $levels : [] as $row) {
            $n = is_array($row) && is_int($row['customers'] ?? null) ? $row['customers'] : 0;
            $total += $n;
            $low += ($row['level'] ?? null) === 'low' ? $n : 0;
        }

        if ($total > 0 && $low * 2 > $total) {
            $reasons[] = 'clv_confidence_mostly_low';
        }

        $explained = array_map(fn (array $e) => $e['ref'], $answer['anomalies_explained']);

        foreach (array_keys($context['anomalies']['anomalies'] ?? []) as $i) {
            if (! in_array("anomalies.anomalies.{$i}", $explained, true)) {
                $reasons[] = 'anomaly_unexplained';

                break;
            }
        }

        if (($context['data_quality']['warnings'] ?? []) !== []) {
            $reasons[] = 'data_quality_warnings';
        }

        if ($reasons === []) {
            return $answer;
        }

        $answer['confidence'] = 'medium';
        $answer['meta']['adjustments'][] = ['field' => 'confidence', 'from' => 'high', 'to' => 'medium', 'reasons' => $reasons];

        return $answer;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function check(mixed $value, array $schema, string $path, array &$errors): void
    {
        $label = $path === '' ? '$' : $path;
        $type = $schema['type'];

        $ok = match ($type) {
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ! array_is_list($value),
            default => false,
        };

        if (! $ok) {
            $errors[] = "{$label}: expected {$type}";

            return;
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$label}: not one of the allowed values";
        }

        $limit = OutputSchema::limits()[preg_replace('/\.\d+(?=\.|$)/', '.*', $path)] ?? [];

        if (is_string($value) && isset($limit['maxLength']) && mb_strlen($value) > $limit['maxLength']) {
            $errors[] = "{$label}: longer than {$limit['maxLength']} characters";
        }

        if (is_array($value) && isset($limit['maxItems']) && count($value) > $limit['maxItems']) {
            $errors[] = "{$label}: more than {$limit['maxItems']} items";
        }

        if ($type === 'array') {
            foreach ($value as $i => $item) {
                $this->check($item, $schema['items'], ($path === '' ? '' : $path.'.').$i, $errors);
            }
        }

        if ($type === 'object') {
            foreach (array_diff(array_map('strval', array_keys($value)), array_keys($schema['properties'])) as $unknown) {
                $errors[] = ($path === '' ? '' : $path.'.').'<unknown property>: not allowed';
            }

            foreach ($schema['required'] ?? [] as $required) {
                if (! array_key_exists($required, $value)) {
                    $errors[] = ($path === '' ? '' : $path.'.').$required.': required';
                }
            }

            foreach ($schema['properties'] as $name => $child) {
                if (array_key_exists($name, $value)) {
                    $this->check($value[$name], $child, ($path === '' ? '' : $path.'.').$name, $errors);
                }
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $answer
     * @param  array<string, mixed>  $context
     * @param  list<string>  $errors
     */
    private function ground(array $answer, array $context, array &$errors): void
    {
        foreach (['key_metrics', 'insights', 'risks', 'opportunities'] as $section) {
            foreach ($answer[$section] as $i => $item) {
                foreach ($item['metric_refs'] as $j => $ref) {
                    if (! $this->resolves($ref, $context)) {
                        $errors[] = "{$section}.{$i}.metric_refs.{$j}: unknown reference (not a path in the data)";
                    }
                }

                if (($section === 'key_metrics' || ($section === 'insights' && $item['kind'] === 'fact')) && $item['metric_refs'] === []) {
                    $errors[] = "{$section}.{$i}.metric_refs: at least one reference is required here";
                }
            }
        }

        foreach ($answer['key_metrics'] as $i => $item) {
            $ref = $item['metric_refs'][0] ?? null;

            if ($ref === null || ! $this->resolves($ref, $context)) {
                continue;   // already reported above
            }

            $actual = $this->valueAt($ref, $context);

            if (is_int($actual) || is_float($actual)) {
                $stated = $this->statedNumber($item['value']);

                if ($stated === null) {
                    $errors[] = "key_metrics.{$i}.value: must state the number found at its first reference";
                } elseif (! $this->matches($stated, $actual)) {
                    $errors[] = "key_metrics.{$i}.value: does not match the number at its first reference";
                }
            }
        }

        foreach ($answer['anomalies_explained'] as $i => $item) {
            if (! str_starts_with($item['ref'], 'anomalies.anomalies.') || ! $this->resolves($item['ref'], $context)) {
                $errors[] = "anomalies_explained.{$i}.ref: must be the path of an anomaly in the data";
            }
        }
    }

    /**
     * Numeric reconciliation of key_metrics (only these: free text cannot be checked reliably — "a bit more than a tenth" has no
     * single number to compare). The FIRST metric_ref decides. Rules: the first number in `value` after dropping thousands
     * separators (',' and the Arabic one), spaces and unit words; an unsigned statement may be the absolute value of a negative
     * metric ("12%" for -12), a signed one must have the right sign; rounding is allowed to the precision STATED (83% for 83.33,
     * 83.3 for 83.33) but never beyond (84% for 83.33 fails, and money, being integers, must match exactly). A reference that is not a
     * plain number (an object, a list, a string) is not compared.
     *
     * @return array{value: float, decimals: int, signed: bool}|null
     */
    private function statedNumber(string $text): ?array
    {
        $clean = str_replace([',', mb_chr(0x066C), ' ', "\u{00A0}"], '', $text);

        if (preg_match('/([+\-\x{2212}]?)(\d+)(?:\.(\d+))?/u', $clean, $m) !== 1) {
            return null;
        }

        $value = (float) ($m[2].(isset($m[3]) ? '.'.$m[3] : ''));

        return ['value' => $m[1] === '' || $m[1] === '+' ? $value : -$value, 'decimals' => strlen($m[3] ?? ''), 'signed' => $m[1] !== ''];
    }

    /** @param array{value: float, decimals: int, signed: bool} $stated */
    private function matches(array $stated, int|float $actual): bool
    {
        $tolerance = 0.5 * 10 ** -$stated['decimals'] + 1e-9;
        $candidates = $stated['signed'] ? [$stated['value']] : [$stated['value'], -$stated['value']];

        foreach ($candidates as $candidate) {
            if (abs($candidate - $actual) <= $tolerance) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $context */
    private function valueAt(string $ref, array $context): mixed
    {
        $node = $context;

        foreach (explode('.', $ref) as $segment) {
            if (! is_array($node)) {
                return null;
            }

            $node = array_key_exists($segment, $node) ? $node[$segment] : ($node[(int) $segment] ?? null);
        }

        return $node;
    }

    /** @param array<string, mixed> $context */
    private function resolves(string $ref, array $context): bool
    {
        if (preg_match('/\A[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*\z/', $ref) !== 1) {
            return false;
        }

        $node = $context;

        foreach (explode('.', $ref) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node) && ! (ctype_digit($segment) && array_key_exists((int) $segment, $node))) {
                return false;
            }

            $node = $node[$segment] ?? $node[(int) $segment];
        }

        return true;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private function normalizeDigits(array $node): array
    {
        foreach ($node as $key => $value) {
            $node[$key] = match (true) {
                is_string($value) => Digits::toAscii($value),
                is_array($value) => $this->normalizeDigits($value),
                default => $value,
            };
        }

        return $node;
    }
}
