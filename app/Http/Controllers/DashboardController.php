<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DashboardRequest;
use App\Modules\Analytics\Services\AnalyticsService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * P6-06: PRD §18's Dashboard — read-only, behind `dashboard.view`. One Service call, one Inertia
 * response; `AnalyticsService::dashboard()` already returns a page-ready array (same convention as
 * `RfmPageController`/`RfmPageService`).
 */
final class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, AnalyticsService $analytics): Response
    {
        return Inertia::render('dashboard', [
            'filters' => $request->echo(),
            'data' => $analytics->dashboard($request->period()),
        ]);
    }
}
