<?php

declare(strict_types=1);

namespace App\Http\Controllers\Metrics;

use App\Http\Controllers\Controller;
use App\Support\MetricsGuideService;
use Inertia\Inertia;
use Inertia\Response;

/** P6-18 phase 5: PRD §12-§18's "راهنمای شاخص‌ها" — read-only, behind analytics.view (same gate as Cohort/Retention/Affinity). One Service call, one Inertia response. */
final class MetricsGuideController extends Controller
{
    public function __invoke(MetricsGuideService $guide): Response
    {
        return Inertia::render('metrics/guide', [
            'data' => $guide->guide(),
        ]);
    }
}
