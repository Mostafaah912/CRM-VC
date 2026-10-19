<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AnalystPageRequest;
use App\Modules\Ai\Services\AnalystPageService;
use Illuminate\Http\JsonResponse;

/** P7-09b: the dashboard widget's data (newest daily_brief in short form + live flags) — read-only JSON behind ai.view, fetched once, no polling. */
final class AnalystWidgetController extends Controller
{
    public function __invoke(AnalystPageRequest $request, AnalystPageService $page): JsonResponse
    {
        return response()->json($page->widget());
    }
}
