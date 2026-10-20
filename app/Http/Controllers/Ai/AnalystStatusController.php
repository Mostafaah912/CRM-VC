<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AnalystTypeRequest;
use App\Modules\Ai\Services\ManualGenerationService;
use Illuminate\Http\JsonResponse;

/** P7-09a: the progress of a manual generation (idle | queued | running | done | failed), polled by the screen — behind ai.view. */
final class AnalystStatusController extends Controller
{
    public function __invoke(AnalystTypeRequest $request, ManualGenerationService $manual): JsonResponse
    {
        return response()->json($manual->state($request->type()));
    }
}
