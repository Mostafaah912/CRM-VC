<?php

declare(strict_types=1);

namespace App\Modules\Sync\Models;

use App\Modules\Sync\Enums\SyncLogLevel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a run's log (PRD §09 sync_logs). Messages are capped at the column's 500 characters and carry no payloads,
 * phone numbers or credentials; context holds counts and ids only. Written only by SyncService.
 *
 * @property int $id
 * @property int $sync_job_id
 * @property SyncLogLevel $level
 * @property string $message
 * @property array<string, mixed>|null $context
 * @property CarbonImmutable $created_at
 */
class SyncLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['sync_job_id', 'level', 'message', 'context'];

    protected function casts(): array
    {
        return [
            'level' => SyncLogLevel::class,
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SyncJob, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SyncJob::class, 'sync_job_id');
    }
}
