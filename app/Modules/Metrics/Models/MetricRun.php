<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Models;

use App\Modules\Metrics\Enums\MetricRunMode;
use App\Modules\Metrics\Enums\MetricRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One Metrics Engine run (PRD §09/§11). Written by MetricRunService; every recomputation, full or
 * dirty, gets exactly one row so a run is always auditable and its `thresholds` snapshot makes it
 * reproducible (CLAUDE.md §4).
 *
 * @property int $id
 * @property MetricRunMode $mode
 * @property string $definition_version
 * @property MetricRunStatus $status
 * @property int $customers_processed
 * @property array<string, mixed>|null $thresholds
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property string|null $error
 */
class MetricRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'mode',
        'definition_version',
        'status',
        'customers_processed',
        'thresholds',
        'started_at',
        'finished_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'mode' => MetricRunMode::class,
            'status' => MetricRunStatus::class,
            'thresholds' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
