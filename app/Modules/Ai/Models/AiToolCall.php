<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PRD §09 ai_tool_calls — the audit trail of the read-only tools whose output went into an insight's context.
 * `error` is a model-safe sentence from the tool, never raw data. Written only by AnalystService, inside the insight's transaction.
 *
 * @property int $id
 * @property int $insight_id
 * @property string $tool_name
 * @property array<string, mixed> $arguments
 * @property int|null $result_size
 * @property int|null $duration_ms
 * @property string|null $error
 */
class AiToolCall extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ai_tool_calls';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['arguments' => 'array'];
    }
}
