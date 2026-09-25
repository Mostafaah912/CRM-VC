<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use InvalidArgumentException;
use JsonException;

/**
 * The ONE place a page position becomes an opaque string and back (timeline, orders, products, notes). The cursor is URL-safe base64
 * (no padding, so it needs no percent-encoding) of a JSON object whose keys are exactly the shape the reader expects:
 *
 *   'instant' — a UTC ISO 8601 time, `Y-m-d\TH:i:s\Z`, which must be a real date and read back identically
 *   'id'      — a whole number >= 1
 *   'text'    — a string of at most TEXT_MAX_LENGTH characters (a product name)
 *
 * Decoding is strict: base64 strict mode, exactly the keys in the shape and in its order, every value of its kind, at most
 * $maxLength characters. Anything else throws InvalidArgumentException. A decoded position is only ever bound as a query
 * VALUE by the caller; it is never part of SQL text. Pure: no Laravel, no database.
 */
final class PageCursor
{
    public const DEFAULT_MAX_LENGTH = 2048;

    public const TEXT_MAX_LENGTH = 255;

    private const INSTANT = 'Y-m-d\TH:i:s\Z';

    /** @param array<string, CarbonImmutable|int|string> $position */
    public static function encode(array $position): string
    {
        $data = [];

        foreach ($position as $key => $value) {
            $data[$key] = $value instanceof CarbonImmutable ? $value->utc()->format(self::INSTANT) : $value;
        }

        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @param  array<string, 'instant'|'id'|'text'>  $shape  key => kind, in the order the keys must appear
     *
     * @throws InvalidArgumentException when the cursor is anything but exactly what encode() makes for this shape
     */
    public static function decode(string $cursor, array $shape, int $maxLength = self::DEFAULT_MAX_LENGTH): CursorPosition
    {
        if ($cursor === '' || strlen($cursor) > $maxLength || preg_match('/^[A-Za-z0-9_-]+$/', $cursor) !== 1) {
            throw self::malformed();
        }

        // Strict mode refuses any byte outside the base64 alphabet; PHP accepts the missing '=' padding, so none is restored.
        $binary = base64_decode(strtr($cursor, '-_', '+/'), true);

        try {
            $data = $binary === false ? null : json_decode($binary, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }

        if (! is_array($data) || array_keys($data) !== array_keys($shape)) {
            throw self::malformed();
        }

        $values = [];

        foreach ($shape as $key => $kind) {
            $values[$key] = match ($kind) {
                'instant' => self::instant($data[$key]),
                'id' => is_int($data[$key]) && $data[$key] >= 1 ? $data[$key] : throw self::malformed(),
                'text' => is_string($data[$key]) && mb_strlen($data[$key]) <= self::TEXT_MAX_LENGTH ? $data[$key] : throw self::malformed(),
            };
        }

        return new CursorPosition($values);
    }

    private static function instant(mixed $value): CarbonImmutable
    {
        if (! is_string($value)) {
            throw self::malformed();
        }

        try {
            $at = CarbonImmutable::createFromFormat('!'.self::INSTANT, $value, 'UTC');
        } catch (InvalidFormatException) {
            $at = null;
        }

        // createFromFormat rolls an impossible date (Feb 31) forward instead of failing: reject anything that does not read back the same.
        if ($at === null || $at->format(self::INSTANT) !== $value) {
            throw self::malformed();
        }

        return $at;
    }

    private static function malformed(): InvalidArgumentException
    {
        return new InvalidArgumentException('Malformed page cursor.');
    }
}
