<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\OrderShowRequest;
use App\Modules\Orders\Services\OrderShowService;
use Inertia\Inertia;
use Inertia\Response;

class OrderShowController extends Controller
{
    public function __invoke(OrderShowRequest $request, OrderShowService $orders): Response
    {
        return Inertia::render('orders/show', [
            'order' => $orders->show($request->orderId())->toArray(),
        ]);
    }
}
