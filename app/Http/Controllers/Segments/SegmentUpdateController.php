<?php

declare(strict_types=1);

namespace App\Http\Controllers\Segments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Segments\SegmentUpdateRequest;
use App\Modules\Segments\Services\SegmentService;
use Illuminate\Http\RedirectResponse;

class SegmentUpdateController extends Controller
{
    public function __invoke(SegmentUpdateRequest $request, SegmentService $segments): RedirectResponse
    {
        $segment = $segments->modify($request->segmentModel(), $request->attributes(), $request->actor());

        return to_route('segments.show', $segment)->with('success', 'سگمنت به‌روزرسانی شد.');
    }
}
