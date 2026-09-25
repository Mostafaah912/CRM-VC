<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\CustomerPageRequest;
use App\Modules\Customers\Services\CustomerOrdersService;
use Illuminate\Http\JsonResponse;

class CustomerOrdersController extends Controller
{
    public function __invoke(CustomerPageRequest $request, CustomerOrdersService $orders): JsonResponse
    {
        return response()->json($orders->pageFor($request->customerId(), $request->cursor(), $request->perPage())->toArray());
    }
}
