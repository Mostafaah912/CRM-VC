<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Services\CohortSnapshotService;
use Inertia\Inertia;
use Inertia\Response;

/** P6-08: PRD §15's Cohort matrix page — read-only, behind analytics.view. One Service call (CohortSnapshotService::matrix(), already built in P6-06), one Inertia response. */
final class CohortPageController extends Controller
{
    public function __invoke(CohortSnapshotService $cohorts): Response
    {
        return Inertia::render('analytics/cohort', [
            'data' => $cohorts->matrix(),
        ]);
    }
}
