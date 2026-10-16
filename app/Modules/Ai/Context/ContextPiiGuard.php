<?php

declare(strict_types=1);

namespace App\Modules\Ai\Context;

/**
 * Defence in depth on the FINAL context (the tools are already aggregate-only). Walks the decoded JSON: forbidden KEY names
 * anywhere; and, for every STRING leaf (numbers are never scanned — a 10-digit revenue must not look like a phone), an Iranian
 * mobile, an email, a run of 7+ Persian/Arabic-Indic digits, or any configured denylist entry. Throws instead of returning
 * a polluted context.
 */
final class ContextPiiGuard
{
    private const FORBIDDEN_KEY = '/^(customer_?ids?|phone\w*|mobile\w*|email\w*|first_?name|last_?name|display_?name|full_?name|address\w*|national_?id|postal\w*)$/i';

    /** @param array<array-key, mixed> $payload */
    public function assertClean(array $payload): void
    {
        /** @var list<string> $denylist */
        $denylist = array_values(array_filter((array) config('ai.context.pii_denylist', []), 'is_string'));
        $this->walk($payload, '$', $denylist);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $denylist
     */
    private function walk(array $node, string $path, array $denylist): void
    {
        foreach ($node as $key => $value) {
            $here = $path.'.'.$key;

            if (is_string($key) && preg_match(self::FORBIDDEN_KEY, $key) === 1) {
                throw new ContextPiiException("Context refused: forbidden key at {$here}.");
            }

            if (is_array($value)) {
                $this->walk($value, $here, $denylist);
            } elseif (is_string($value)) {
                $kind = $this->kind($value, $denylist);

                if ($kind !== null) {
                    throw new ContextPiiException("Context refused: {$kind} found at {$here}.");
                }
            }
        }
    }

    /** @param list<string> $denylist */
    private function kind(string $value, array $denylist): ?string
    {
        $ascii = strtr($value, self::digitMap());

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

    /**
     * Persian (U+06F0..) and Arabic-Indic (U+0660..) digits to ASCII, built from code points — this class only CONSUMES
     * such digits to catch phone numbers; P6-12's arch rule forbids them as literals in app/.
     *
     * @return array<string, string>
     */
    private static function digitMap(): array
    {
        $map = [];

        foreach (range(0, 9) as $digit) {
            $map[mb_chr(0x06F0 + $digit)] = (string) $digit;
            $map[mb_chr(0x0660 + $digit)] = (string) $digit;
        }

        return $map;
    }
}
