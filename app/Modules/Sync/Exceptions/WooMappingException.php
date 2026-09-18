<?php

declare(strict_types=1);

namespace App\Modules\Sync\Exceptions;

/**
 * A Woo payload that cannot become a DTO: a required field is missing or malformed.
 * Carries the entity and the field PATH ('line_items.0.total') and names the type it saw —
 * never the value itself, which can be a phone number or a name. A WooException, so sync code
 * that fails a job on Woo problems handles it like any other bad answer from Woo.
 */
final class WooMappingException extends WooException
{
    public function __construct(
        public readonly string $entity,
        public readonly string $field,
        string $expected,
        string $got,
    ) {
        parent::__construct("Woo {$entity} payload is invalid at '{$field}': expected {$expected}, got {$got}.");
    }
}
