<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AnalystPageRequest;
use App\Modules\Ai\Services\AnalystPageService;
use Inertia\Inertia;
use Inertia\Response;

/** P7-09a: the «تحلیلگر هوشمند» page — read-only, behind ai.view. One Service call, one Inertia response. */
final class AnalystPageController extends Controller
{
    public function __invoke(AnalystPageRequest $request, AnalystPageService $page): Response
    {
        return Inertia::render('ai/analyst', [
            'data' => $page->overview($request->user()?->id, $request->canSeeCost()),
        ]);
    }
}
