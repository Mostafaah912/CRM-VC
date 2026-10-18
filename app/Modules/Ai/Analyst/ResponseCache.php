<?php

declare(strict_types=1);

namespace App\Modules\Ai\Analyst;

use App\Modules\Ai\Models\AiInsight;

/**
 * Cache key = type | prompt version | model hash | context hash — deterministic, <= 120 characters (ai_insights.cache_key is varchar(120)).
 * The context contains as_of, so new data is a new key; changing the prompt version or the model is a new key too. lookup() reads
 * ai_insights only (P7-08 writes the rows) and honours a TTL.
 */
final class ResponseCache
{
    public function key(string $type, string $promptVersion, string $model, string $contextJson): string
    {
        return $type.'|'.$promptVersion.'|'.substr(hash('sha256', $model), 0, 12).'|'.substr(hash('sha256', $contextJson), 0, 40);
    }

    /** @return array<string, mixed>|null the stored payload of a fresh row with this key */
    public function lookup(string $key): ?array
    {
        return $this->find($key)?->payload;
    }

    /** The fresh row itself (P7-08 returns it on a hit). */
    public function find(string $key): ?AiInsight
    {
        $ttl = max(1, (int) config('ai.cache.ttl_minutes', 1440));

        return AiInsight::query()->where('cache_key', $key)->where('created_at', '>=', now()->subMinutes($ttl))->orderByDesc('id')->first();
    }
}
