<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Services\RetentionService;
use Inertia\Inertia;
use Inertia\Response;

/** P6-08: PRD §15's Retention page — read-only, behind analytics.view. One Service call (RetentionService::summary(), P6-08's thin composition of P6-04's three already-tested methods), one Inertia response. */
final class RetentionPageController extends Controller
{
    public function __invoke(RetentionService $retention): Response
    {
        return Inertia::render('analytics/retention', [
            'data' => $retention->summary(),
        ]);
    }
}
