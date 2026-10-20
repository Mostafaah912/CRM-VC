<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AnalystTypeRequest;
use App\Modules\Ai\Services\AnalystPageService;
use Illuminate\Http\JsonResponse;

/** P7-09a: one page of the history of one analysis type (JSON) — read-only, behind ai.view. */
final class AnalystHistoryController extends Controller
{
    public function __invoke(AnalystTypeRequest $request, AnalystPageService $page): JsonResponse
    {
        return response()->json($page->history($request->type(), $request->pageNumber(), $request->perPage(), $request->canSeeCost()));
    }
}
