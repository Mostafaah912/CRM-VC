<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\CustomerPageRequest;
use App\Modules\Customers\Services\CustomerProductsService;
use Illuminate\Http\JsonResponse;

class CustomerProductsController extends Controller
{
    public function __invoke(CustomerPageRequest $request, CustomerProductsService $products): JsonResponse
    {
        return response()->json($products->pageFor($request->customerId(), $request->cursor(), $request->perPage())->toArray());
    }
}
