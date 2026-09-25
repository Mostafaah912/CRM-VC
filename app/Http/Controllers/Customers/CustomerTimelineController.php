<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\CustomerTimelineRequest;
use App\Modules\Customers\Services\CustomerTimelineService;
use Illuminate\Http\JsonResponse;

class CustomerTimelineController extends Controller
{
    public function __invoke(CustomerTimelineRequest $request, CustomerTimelineService $timeline): JsonResponse
    {
        return response()->json($timeline->pageFor($request->customerId(), $request->cursor(), $request->perPage())->toArray());
    }
}
