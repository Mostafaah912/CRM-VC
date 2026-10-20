<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\CustomerAnalystPanelRequest;
use App\Support\CustomerAnalystPanelService;
use Illuminate\Http\JsonResponse;

/** P7-09b: the aggregate analyst panel of Customer 360 (JSON) — behind customers.view; no AI call, no per-customer data in the answer. */
final class CustomerAnalystPanelController extends Controller
{
    public function __invoke(CustomerAnalystPanelRequest $request, CustomerAnalystPanelService $panel): JsonResponse
    {
        return response()->json($panel->for($request->customerId(), $request->canSeeSegments(), $request->canSeeInsight()));
    }
}
