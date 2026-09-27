<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DrillRequest;
use App\Modules\Analytics\Services\DrillService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * P6-07's audited CSV export of one drill-down (PRD §18: "Export"). `DrillService::export()` itself
 * checks `customers.export` (so the Service enforces it regardless of caller, same as
 * `SegmentService::export()`) — the route below also gates on it, for a plain 403 on a direct visit.
 */
final class DrillExportController extends Controller
{
    public function __invoke(DrillRequest $request, DrillService $drill): StreamedResponse
    {
        return $drill->export($request->widget(), $request->period(), $request->widgetParams(), $request->actor());
    }
}
