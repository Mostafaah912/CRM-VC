<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

/**
 * What the health page shows, ready to send: the last good sync run, the sync queue's depth and the failed-jobs count (null when
 * the queue backend cannot be read), the latest reconciled months and the GATE 1 panel. Nothing here is a cursor, a credential,
 * a payload or a trace.
 */
final readonly class SyncHealthReport
{
    /**
     * @param  list<ReconciliationMonthSummary>  $recentMonths  newest first
     */
    public function __construct(
        public ?SyncRunRow $lastSync,
        public ?int $syncQueueDepth,
        public ?int $failedJobs,
        public array $recentMonths,
        public GateOneReport $gate,
    ) {}

    /**
     * @return array{
     *     last_sync: array{started_at: string, finished_at: string|null, duration_seconds: int|null, pages_processed: int, records_processed: int}|null,
     *     queue: array{sync_depth: int|null, failed_jobs: int|null},
     *     reconciliation: list<array{month: string, status: string, count_diff: int|null, diff_percent: string|null, error: string|null}>,
     *     gate_one: array{passed: bool, total_months: int, green_months: int, missing_months: list<string>, failing_months: list<array{month: string, status: string, count_diff: int|null, diff_percent: string|null}>}
     * }
     */
    public function toArray(): array
    {
        $failing = [];

        foreach ($this->gate->months as $month) {
            if ($month->status !== null && ! $month->isGreen()) {
                $failing[] = [
                    'month' => $month->month,
                    'status' => $month->status->value,
                    'count_diff' => $month->countDiff,
                    'diff_percent' => $month->revenueDiffPct,
                ];
            }
        }

        return [
            'last_sync' => $this->lastSync === null ? null : [
                'started_at' => $this->lastSync->startedAt,
                'finished_at' => $this->lastSync->finishedAt,
                'duration_seconds' => $this->lastSync->durationSeconds,
                'pages_processed' => $this->lastSync->pagesProcessed,
                'records_processed' => $this->lastSync->recordsProcessed,
            ],
            'queue' => ['sync_depth' => $this->syncQueueDepth, 'failed_jobs' => $this->failedJobs],
            'reconciliation' => array_map(fn (ReconciliationMonthSummary $m): array => [
                'month' => $m->month,
                'status' => $m->status->value,
                'count_diff' => $m->countDiff,
                'diff_percent' => $m->diffPercent,
                'error' => $m->error,
            ], $this->recentMonths),
            'gate_one' => [
                'passed' => $this->gate->passed,
                'total_months' => count($this->gate->months),
                'green_months' => count(array_filter($this->gate->months, fn (GateOneMonth $m): bool => $m->isGreen())),
                'missing_months' => $this->gate->missingMonths(),
                'failing_months' => $failing,
            ],
        ];
    }
}
