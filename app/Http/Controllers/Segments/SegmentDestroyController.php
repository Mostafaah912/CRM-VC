<?php

declare(strict_types=1);

namespace App\Http\Controllers\Segments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Segments\SegmentDestroyRequest;
use App\Modules\Segments\Services\SegmentService;
use Illuminate\Http\RedirectResponse;

class SegmentDestroyController extends Controller
{
    public function __invoke(SegmentDestroyRequest $request, SegmentService $segments): RedirectResponse
    {
        $segments->destroy($request->segmentModel(), $request->actor());

        return to_route('segments.index')->with('success', 'سگمنت حذف شد.');
    }
}
