<?php

declare(strict_types=1);

namespace App\Modules\Ai\Events;

/** PRD §07: a new insight was stored. Ids only. */
final readonly class AiInsightGenerated
{
    public function __construct(public int $insightId, public string $type, public string $scope) {}
}
