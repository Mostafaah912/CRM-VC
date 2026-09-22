<?php

declare(strict_types=1);

namespace App\Http\Controllers\Metrics;

use App\Http\Controllers\Controller;
use App\Modules\Metrics\Services\RfmPageService;
use Inertia\Inertia;
use Inertia\Response;

/** P4-08 part B: PRD §12's RFM page — read-only, behind metrics.view. No route parameters. */
final class RfmPageController extends Controller
{
    public function __invoke(RfmPageService $rfm): Response
    {
        return Inertia::render('metrics/rfm', [
            'data' => $rfm->getData(),
        ]);
    }
}
