<?php

declare(strict_types=1);

namespace App\Http\Controllers\Segments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Segments\SegmentEvaluateRequest;
use App\Modules\Segments\Jobs\EvaluateSegmentJob;
use Illuminate\Http\RedirectResponse;

/** Queues EvaluateSegmentJob — never runs evaluate() inline (see the Job's docblock for the measured timing that decided this). */
class SegmentEvaluateController extends Controller
{
    public function __invoke(SegmentEvaluateRequest $request): RedirectResponse
    {
        EvaluateSegmentJob::dispatch($request->segmentModel()->id);

        return back()->with('success', 'ارزیابی سگمنت در صف قرار گرفت؛ چند لحظه‌ی دیگر صفحه را تازه کنید.');
    }
}
