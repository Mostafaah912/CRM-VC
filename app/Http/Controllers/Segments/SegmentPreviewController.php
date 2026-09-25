<?php

declare(strict_types=1);

namespace App\Http\Controllers\Segments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Segments\SegmentPreviewRequest;
use App\Modules\Segments\Services\SegmentService;
use Illuminate\Http\JsonResponse;

/** P5-05: the Rule Builder's live "N مشتری" count for a draft rule that may not be saved yet. */
class SegmentPreviewController extends Controller
{
    public function __invoke(SegmentPreviewRequest $request, SegmentService $segments): JsonResponse
    {
        return response()->json(['count' => $segments->previewRule($request->rule())]);
    }
}
