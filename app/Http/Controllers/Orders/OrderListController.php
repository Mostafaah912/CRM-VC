<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\OrderListRequest;
use App\Modules\Orders\Services\OrderListService;
use Inertia\Inertia;
use Inertia\Response;

class OrderListController extends Controller
{
    public function __invoke(OrderListRequest $request, OrderListService $orders): Response
    {
        return Inertia::render('orders/index', [
            'orders' => $orders->paginate($request->filters(), $request->page()),
            'filters' => $request->echo(),
            'options' => ['statuses' => $orders->statusOptions()],
        ]);
    }
}
