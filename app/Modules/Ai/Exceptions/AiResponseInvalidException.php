<?php

declare(strict_types=1);

namespace App\Modules\Ai\Exceptions;

use RuntimeException;

/**
 * The model answered twice (original + one corrective retry) and neither answer passed the schema / grounding checks.
 * `errors` are validator findings (JSON path + rule) — safe to log; the model's raw text is never carried.
 */
final class AiResponseInvalidException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors, public readonly int $attempts)
    {
        parent::__construct("The AI response was invalid after {$attempts} attempts (".count($errors).' problems).');
    }
}
