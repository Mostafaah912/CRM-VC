<?php

declare(strict_types=1);

namespace App\Http\Controllers\Segments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Segments\SegmentShowRequest;
use App\Modules\Segments\Services\SegmentService;
use App\Modules\Segments\Support\SegmentDetail;
use Inertia\Inertia;
use Inertia\Response;

class SegmentShowController extends Controller
{
    public function __invoke(SegmentShowRequest $request, SegmentService $segments): Response
    {
        $segment = $request->segmentModel();

        return Inertia::render('segments/show', [
            'segment' => SegmentDetail::fromModel($segment)->toArray(),
            'members' => $segments->members($segment, $request->page()),
        ]);
    }
}
