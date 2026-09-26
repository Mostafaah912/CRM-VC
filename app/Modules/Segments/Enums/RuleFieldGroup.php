<?php

declare(strict_types=1);

namespace App\Modules\Segments\Enums;

/** The three field groups from PRD §17's whitelist table — tells RuleCompiler (P5-03) which table/subquery a field belongs to. */
enum RuleFieldGroup: string
{
    case Customer = 'customer';
    case Metrics = 'metrics';
    case Behavior = 'behavior';
}
