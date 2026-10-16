<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * PRD §09 ai_insights. P7-07 only READS it (ResponseCache lookup); writing a generated insight is P7-08.
 *
 * @property int $id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property string $prompt_version
 * @property string|null $cache_key
 * @property CarbonImmutable $created_at
 */
class AiInsight extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ai_insights';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
