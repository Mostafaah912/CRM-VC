<?php

declare(strict_types=1);

namespace App\Modules\Ai\Dto;

/** What a scheduled run did: generated | cache_hit | skipped (with a reason code). Jobs log it; nothing here carries model text. */
final readonly class ScheduledOutcome
{
    public function __construct(public string $status, public ?string $reason = null, public ?int $insightId = null) {}

    public static function generated(int $id): self
    {
        return new self('generated', null, $id);
    }

    public static function cacheHit(int $id): self
    {
        return new self('cache_hit', null, $id);
    }

    public static function skipped(string $reason): self
    {
        return new self('skipped', $reason);
    }
}
