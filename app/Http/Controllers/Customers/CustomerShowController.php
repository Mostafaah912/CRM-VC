<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\CustomerShowRequest;
use App\Modules\Customers\Services\CustomerShowService;
use Inertia\Inertia;
use Inertia\Response;

class CustomerShowController extends Controller
{
    public function __invoke(CustomerShowRequest $request, CustomerShowService $customers): Response
    {
        return Inertia::render('customers/show', [
            'profile' => $customers->show($request->customerId())->toArray(),
        ]);
    }
}
