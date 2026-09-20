<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\CustomerListRequest;
use App\Modules\Customers\Services\CustomerListService;
use Inertia\Inertia;
use Inertia\Response;

class CustomerListController extends Controller
{
    public function __invoke(CustomerListRequest $request, CustomerListService $customers): Response
    {
        return Inertia::render('customers/index', [
            'customers' => $customers->paginate($request->viewer(), $request->filters(), $request->page()),
            'filters' => $request->echo(),
            'options' => $customers->filterOptions($request->filters()->province),
        ]);
    }
}
