<?php

declare(strict_types=1);

namespace App\Http\Controllers\Segments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Segments\SegmentEditRequest;
use App\Modules\Segments\Services\SegmentService;
use Inertia\Inertia;
use Inertia\Response;

class SegmentEditController extends Controller
{
    public function __invoke(SegmentEditRequest $request, SegmentService $segments): Response
    {
        $segment = $request->segmentModel();

        return Inertia::render('segments/edit', [
            'segment' => [
                'id' => $segment->id,
                'name' => $segment->name,
                'description' => $segment->description,
                'rule' => $segment->rule,
                'is_system' => $segment->is_system,
            ],
            'whitelist' => $segments->whitelist(),
        ]);
    }
}
