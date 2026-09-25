<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductListRequest;
use App\Modules\Catalog\Services\ProductListService;
use Inertia\Inertia;
use Inertia\Response;

class ProductListController extends Controller
{
    public function __invoke(ProductListRequest $request, ProductListService $products): Response
    {
        return Inertia::render('products/index', [
            'products' => $products->paginate($request->filters(), $request->page()),
            'filters' => $request->echo(),
            'options' => ['statuses' => $products->statusOptions()],
        ]);
    }
}
