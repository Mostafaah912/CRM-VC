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

        return ['payload' => $clean, 'errors' => [], 'redactions' => count($redactions)];
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

        foreach ($answer['anomalies_explained'] as $i => $item) {
            if (! str_starts_with($item['ref'], 'anomalies.anomalies.') || ! $this->resolves($item['ref'], $context)) {
                $errors[] = "anomalies_explained.{$i}.ref: must be the path of an anomaly in the data";
            }
        }
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
