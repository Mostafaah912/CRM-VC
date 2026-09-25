<?php

declare(strict_types=1);

namespace App\Modules\Sync\Models;

use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Enums\SyncMode;
use App\Modules\Sync\Enums\SyncStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One sync run (PRD §09 sync_jobs). cursor_from/cursor_to are the FROZEN window of the run: cursor_to = now() at start,
 * cursor_from = the stored cursor minus the overlap (null on the very first sync). Written only by SyncService.
 *
 * @property int $id
 * @property SyncEntity $entity
 * @property SyncMode $mode
 * @property SyncStatus $status
 * @property CarbonImmutable|null $cursor_from
 * @property CarbonImmutable $cursor_to
 * @property int $pages_processed
 * @property int $records_processed
 * @property int $records_failed
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property string|null $error
 */
class SyncJob extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'entity', 'mode', 'status', 'cursor_from', 'cursor_to',
        'pages_processed', 'records_processed', 'records_failed',
        'started_at', 'finished_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'entity' => SyncEntity::class,
            'mode' => SyncMode::class,
            'status' => SyncStatus::class,
            'cursor_from' => 'immutable_datetime',
            'cursor_to' => 'immutable_datetime',
            'pages_processed' => 'integer',
            'records_processed' => 'integer',
            'records_failed' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<SyncLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }
}
