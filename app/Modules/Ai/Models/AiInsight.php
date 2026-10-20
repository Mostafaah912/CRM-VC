<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * PRD §09 ai_insights. Read by ResponseCache (P7-07); written ONLY by AnalystService (P7-08), in one transaction with its ai_tool_calls.
 *
 * @property int $id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property string $prompt_version
 * @property string|null $cache_key
 * @property string|null $scope
 * @property string|null $period_start
 * @property string|null $period_end
 * @property string $provider
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string $cost_usd
 * @property int|null $duration_ms
 * @property int|null $requested_by
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
