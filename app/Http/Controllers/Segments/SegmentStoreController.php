<?php

declare(strict_types=1);

namespace App\Http\Controllers\Segments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Segments\SegmentStoreRequest;
use App\Modules\Segments\Services\SegmentService;
use Illuminate\Http\RedirectResponse;

class SegmentStoreController extends Controller
{
    public function __invoke(SegmentStoreRequest $request, SegmentService $segments): RedirectResponse
    {
        $segment = $segments->create($request->attributes(), $request->author());

        return to_route('segments.show', $segment)->with('success', 'سگمنت ساخته شد.');
    }
}
