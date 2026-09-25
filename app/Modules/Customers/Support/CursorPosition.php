<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use Carbon\CarbonImmutable;
use LogicException;

/** A decoded page position: the values PageCursor::decode() validated, read back under the kind they were validated as. */
final readonly class CursorPosition
{
    /** @param array<string, CarbonImmutable|int|string> $values */
    public function __construct(private array $values) {}

    public function instant(string $key): CarbonImmutable
    {
        $value = $this->values[$key] ?? null;

        return $value instanceof CarbonImmutable ? $value : throw new LogicException("Cursor key [{$key}] is not an instant.");
    }

    public function id(string $key): int
    {
        $value = $this->values[$key] ?? null;

        return is_int($value) ? $value : throw new LogicException("Cursor key [{$key}] is not an id.");
    }

    public function text(string $key): string
    {
        $value = $this->values[$key] ?? null;

        return is_string($value) ? $value : throw new LogicException("Cursor key [{$key}] is not text.");
    }
}
