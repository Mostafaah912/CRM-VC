<?php

declare(strict_types=1);

namespace App\Modules\Sync\Models;

use App\Modules\Sync\Enums\SyncStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The stored cursor of one entity (PRD §09 sync_cursors). cursor_value moves ONLY when a run completes (CLAUDE.md §5);
 * consecutive_failures is derived from the run history, never incremented. Written only by SyncService.
 *
 * @property string $entity
 * @property CarbonImmutable|null $cursor_value
 * @property CarbonImmutable|null $last_run_at
 * @property SyncStatus|null $last_status
 * @property int $consecutive_failures
 * @property CarbonImmutable $updated_at
 */
class SyncCursor extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'entity';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['entity', 'cursor_value', 'last_run_at', 'last_status', 'consecutive_failures'];

    protected function casts(): array
    {
        return [
            'cursor_value' => 'immutable_datetime',
            'last_run_at' => 'immutable_datetime',
            'last_status' => SyncStatus::class,
            'consecutive_failures' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
