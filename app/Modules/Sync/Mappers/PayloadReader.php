<?php

declare(strict_types=1);

namespace App\Modules\Sync\Mappers;

use App\Modules\Sync\Exceptions\WooMappingException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Typed, loud access to one raw Woo JSON object. A required field that is missing or malformed
 * throws WooMappingException with its full path — it is never turned into null, 0 or ''.
 * The only null-ish conversions are the ones Woo itself means: absent/''/0 for "no value" on
 * fields that are genuinely optional, and those still fail when the value is malformed.
 * Money is int Toman (CLAUDE.md §2), parsed by App\Support\Money; no float ever comes out.
 */
final readonly class PayloadReader
{
    private const GMT_FORMAT = 'Y-m-d\TH:i:s';

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function __construct(
        private array $data,
        private string $entity,
        private string $path = '',
    ) {}

    public function int(string $key): int
    {
        $value = $this->required($key, 'an integer');

        return is_int($value) ? $value : $this->fail($key, 'an integer', $value);
    }

    public function positiveInt(string $key): int
    {
        $value = $this->int($key);

        return $value >= 1 ? $value : $this->fail($key, 'an integer of at least 1', $value);
    }

    public function nonNegativeInt(string $key): int
    {
        $value = $this->int($key);

        return $value >= 0 ? $value : $this->fail($key, 'a non-negative integer', $value);
    }

    /** Woo sends refund quantities negative; the magnitude is what callers want. */
    public function absoluteInt(string $key): int
    {
        return abs($this->int($key));
    }

    /** A Woo id where 0 (or absent/null) means "none" — e.g. variation_id of a simple product. */
    public function nullableId(string $key): ?int
    {
        $value = $this->data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_int($value) || $value < 0) {
            $this->fail($key, 'a non-negative integer id', $value);
        }

        return $value === 0 ? null : $value;
    }

    /**
     * A Woo id sent as a positive integer or as a canonical digit string — Woo sends meta values (such as
     * _refunded_item_id) as strings. Anything else is malformed, never "no id".
     */
    public function wooId(string $key): int
    {
        $value = $this->required($key, 'a Woo id');

        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1 && (string) (int) $value === $value) {
            return (int) $value;
        }

        $this->fail($key, 'a Woo id', $value);
    }

    public function string(string $key): string
    {
        $value = $this->required($key, 'a string');

        return is_string($value) ? $value : $this->fail($key, 'a string', $value);
    }

    public function nonEmptyString(string $key): string
    {
        $value = $this->string($key);

        return $value !== '' ? $value : $this->fail($key, 'a non-empty string', $value);
    }

    /** Absent, null or '' is "no value" (Woo's own way of saying so); anything else must be a string, kept untouched. */
    public function nullableString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : $this->fail($key, 'a string', $value);
    }

    public function money(string $key): int
    {
        return $this->toman($key, $this->required($key, 'a whole-Toman amount'));
    }

    public function nullableMoney(string $key): ?int
    {
        $value = $this->data[$key] ?? null;

        return $value === null || $value === '' ? null : $this->toman($key, $value);
    }

    /** Woo sends refund totals negative ("-100000"); the magnitude of a clean whole amount is what callers want. */
    public function absoluteMoney(string $key): int
    {
        $value = $this->required($key, 'a signed whole-Toman amount');

        if (! is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            $this->fail($key, 'a signed whole-Toman amount', $value);
        }

        return $this->toman($key, ltrim($value, '-'));
    }

    /** A Woo *_gmt timestamp: exactly Y-m-dTH:i:s, a real date, read as UTC. */
    public function date(string $key): CarbonImmutable
    {
        $value = $this->required($key, 'a GMT timestamp (Y-m-dTH:i:s)');
        $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!'.self::GMT_FORMAT, $value, new DateTimeZone('UTC')) : false;

        if ($parsed === false || $parsed->format(self::GMT_FORMAT) !== $value) {
            $this->fail($key, 'a GMT timestamp (Y-m-dTH:i:s)', $value);
        }

        return CarbonImmutable::instance($parsed);
    }

    /** Absent or null (Woo: not paid / not completed yet) is null; a malformed value is an error, not null. */
    public function nullableDate(string $key): ?CarbonImmutable
    {
        return ($this->data[$key] ?? null) === null ? null : $this->date($key);
    }

    public function object(string $key): self
    {
        $value = $this->required($key, 'an object');

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->fail($key, 'an object', $value);
        }

        return new self($value, $this->entity, $this->field($key));
    }

    /**
     * @return list<self>
     */
    public function objects(string $key): array
    {
        $value = $this->required($key, 'a list of objects');

        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($key, 'a list of objects', $value);
        }

        return $this->readers($key, $value);
    }

    /**
     * Like objects(), but an absent or null list is empty (Woo omits e.g. coupon_lines when there are none).
     *
     * @return list<self>
     */
    public function objectsOrEmpty(string $key): array
    {
        return ($this->data[$key] ?? null) === null ? [] : $this->objects($key);
    }

    /**
     * @param  array<array-key, mixed>  $list
     * @return list<self>
     */
    private function readers(string $key, array $list): array
    {
        $readers = [];

        foreach ($list as $index => $element) {
            if (! is_array($element) || ($element !== [] && array_is_list($element))) {
                $this->fail("{$key}.{$index}", 'an object', $element);
            }

            $readers[] = new self($element, $this->entity, $this->field("{$key}.{$index}"));
        }

        return $readers;
    }

    private function toman(string $key, mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_float($value) && is_finite($value) && $value >= 0 && $value < 9.0e18 && floor($value) === $value) {
            return (int) $value;
        }

        // Canonical digits only: no sign, no decimals, no separators, no leading zeros ('0912…' is a phone, not an amount).
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $amount = Money::parseToman($value);

            // (int) silently saturates on overflow; an amount that does not round-trip is not an amount.
            if ((string) $amount === $value) {
                return $amount;
            }
        }

        $this->fail($key, 'a whole-Toman amount', $value);
    }

    private function required(string $key, string $expected): mixed
    {
        if (! array_key_exists($key, $this->data)) {
            throw new WooMappingException($this->entity, $this->field($key), $expected, 'nothing (the field is missing)');
        }

        return $this->data[$key];
    }

    private function fail(string $key, string $expected, mixed $got): never
    {
        throw new WooMappingException($this->entity, $this->field($key), $expected, get_debug_type($got));
    }

    /** The full path of a key ('line_items.0.meta_data.1.key'), for errors that must point at one field. */
    public function field(string $key): string
    {
        return $this->path === '' ? $key : "{$this->path}.{$key}";
    }
}
