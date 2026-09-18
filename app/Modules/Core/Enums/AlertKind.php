<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

/** The critical conditions listed in PRD §22 ("هشدارها"). */
enum AlertKind: string
{
    case SyncFailure = 'sync_failure';
    case MetricRunFailure = 'metric_run_failure';
    case ReconciliationVariance = 'reconciliation_variance';
    case FailedJobsThreshold = 'failed_jobs_threshold';
    case AiBudgetThreshold = 'ai_budget_threshold';
    case NightlyChainTimeout = 'nightly_chain_timeout';
}
