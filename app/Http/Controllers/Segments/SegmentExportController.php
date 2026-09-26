<?php

declare(strict_types=1);

namespace App\Http\Controllers\Segments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Segments\SegmentExportRequest;
use App\Modules\Segments\Services\SegmentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SegmentExportController extends Controller
{
    public function __invoke(SegmentExportRequest $request, SegmentService $segments): StreamedResponse
    {
        return $segments->export($request->segmentModel(), $request->actor());
    }
}
