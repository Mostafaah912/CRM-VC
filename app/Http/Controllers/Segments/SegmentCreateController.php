<?php

declare(strict_types=1);

namespace App\Http\Controllers\Segments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Segments\SegmentCreateRequest;
use App\Modules\Segments\Services\SegmentService;
use Inertia\Inertia;
use Inertia\Response;

class SegmentCreateController extends Controller
{
    public function __invoke(SegmentCreateRequest $request, SegmentService $segments): Response
    {
        return Inertia::render('segments/create', [
            'whitelist' => $segments->whitelist(),
        ]);
    }
}
