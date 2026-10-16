<?php

declare(strict_types=1);

namespace App\Modules\Ai\Context;

use App\Support\Digits;
use Illuminate\Support\Facades\Log;

/**
 * Defence in depth on text that leaves for the model (the tools are already aggregate-only).
 *
 * - STRING leaves that look like customer data (an Iranian mobile, an email, 7+ Persian/Arabic-Indic digits in a row, or a
 *   configured denylist entry) are REPLACED with "[redacted]" and the run continues: such a string is data-shaped text a human
 *   typed into the shop (a product name, a segment name, a SKU that happens to look like a phone), not a customer record.
 *   Only the JSON path and the kind are logged — never the value. Numbers are never scanned (a 10-digit revenue is not a phone).
 * - A forbidden KEY name anywhere (customer_id, phone, email, first_name ...) means a customer record is structurally present:
 *   that stays fail-closed (ContextPiiException) — nothing is redacted around it, nothing is returned.
 * assertClean() is the strict verification pass: it throws on ANY hit, so a sanitized payload must pass it.
 */
final class ContextPiiGuard
{
    public const REDACTED = '[redacted]';

    private const FORBIDDEN_KEY = '/^(customer_?ids?|phone\w*|mobile\w*|email\w*|first_?name|last_?name|display_?name|full_?name|address\w*|national_?id|postal\w*)$/i';

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{0: array<array-key, mixed>, 1: list<array{path: string, kind: string}>} cleaned payload, redactions made
     */
    public function sanitize(array $payload): array
    {
        $redactions = [];
        $clean = $this->clean($payload, '$', $this->denylist(), $redactions);

        foreach ($redactions as $r) {
            Log::warning('AI text redacted', ['path' => $r['path'], 'kind' => $r['kind']]);
        }

        return [$clean, $redactions];
    }

    /** @param array<array-key, mixed> $payload */
    public function assertClean(array $payload): void
    {
        $denylist = $this->denylist();
        $this->check($payload, '$', $denylist);
    }

    /** @return list<string> */
    private function denylist(): array
    {
        return array_values(array_filter((array) config('ai.context.pii_denylist', []), 'is_string'));
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $denylist
     * @param  list<array{path: string, kind: string}>  $redactions
     * @return array<array-key, mixed>
     */
    private function clean(array $node, string $path, array $denylist, array &$redactions): array
    {
        foreach ($node as $key => $value) {
            $here = $path.'.'.$key;

            if (is_string($key) && preg_match(self::FORBIDDEN_KEY, $key) === 1) {
                throw new ContextPiiException("Context refused: forbidden key at {$here}.");
            }

            if (is_array($value)) {
                $node[$key] = $this->clean($value, $here, $denylist, $redactions);
            } elseif (is_string($value) && ($kind = $this->kind($value, $denylist)) !== null) {
                $node[$key] = self::REDACTED;
                $redactions[] = ['path' => $here, 'kind' => $kind];
            }
        }

        return $node;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $denylist
     */
    private function check(array $node, string $path, array $denylist): void
    {
        foreach ($node as $key => $value) {
            $here = $path.'.'.$key;

            if (is_string($key) && preg_match(self::FORBIDDEN_KEY, $key) === 1) {
                throw new ContextPiiException("Context refused: forbidden key at {$here}.");
            }

            if (is_array($value)) {
                $this->check($value, $here, $denylist);
            } elseif (is_string($value) && ($kind = $this->kind($value, $denylist)) !== null) {
                throw new ContextPiiException("Context refused: {$kind} found at {$here}.");
            }
        }
    }

    /** @param list<string> $denylist */
    private function kind(string $value, array $denylist): ?string
    {
        $ascii = Digits::toAscii($value);

        return match (true) {
            preg_match('/(?<!\d)(?:\+?98|0098|0)?9\d{9}(?!\d)/', $ascii) === 1 => 'mobile number',
            preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/u', $value) === 1 => 'email address',
            preg_match('/[\x{06F0}-\x{06F9}\x{0660}-\x{0669}]{7,}/u', $value) === 1 => 'long digit run',
            $this->denied($value, $denylist) => 'denylisted text',
            default => null,
        };
    }

    /** @param list<string> $denylist */
    private function denied(string $value, array $denylist): bool
    {
        foreach ($denylist as $needle) {
            if ($needle !== '' && mb_stripos($value, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
