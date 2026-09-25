<?php

declare(strict_types=1);

namespace App\Http\Controllers\Segments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Segments\SegmentIndexRequest;
use App\Modules\Segments\Services\SegmentService;
use Inertia\Inertia;
use Inertia\Response;

class SegmentListController extends Controller
{
    public function __invoke(SegmentIndexRequest $request, SegmentService $segments): Response
    {
        return Inertia::render('segments/index', [
            'segments' => $segments->paginate($request->page()),
        ]);
    }
}
