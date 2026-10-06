<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Services\AffinityService;
use Inertia\Inertia;
use Inertia\Response;

/** P6-08: PRD §16's Affinity page (backlog title: "Affinity (4 levels)") — read-only, behind analytics.view. One Service call (AffinityService::topAll(), P6-08's thin composition of the already-tested top()), one Inertia response. */
final class AffinityPageController extends Controller
{
    public function __invoke(AffinityService $affinity): Response
    {
        return Inertia::render('analytics/affinity', [
            'data' => $affinity->topAll(),
        ]);
    }
}
