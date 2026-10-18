<?php

declare(strict_types=1);

namespace App\Modules\Ai\Exceptions;

use RuntimeException;

/** The newest complete day is older than ai.generation.max_data_age_days (or there is none): generating would pay for a misleading answer. */
final class StaleDataException extends RuntimeException
{
    public function __construct(public readonly ?int $ageDays, public readonly int $maxAgeDays)
    {
        parent::__construct($ageDays === null
            ? 'There is no complete day of data to analyse.'
            : "The data is {$ageDays} days old (limit {$maxAgeDays}); generation was refused.");
    }
}
