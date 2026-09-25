<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\PhoneRevealRequest;
use App\Modules\Customers\Services\PhoneRevealService;
use Illuminate\Http\JsonResponse;

class PhoneRevealController extends Controller
{
    public function __invoke(PhoneRevealRequest $request, PhoneRevealService $reveal): JsonResponse
    {
        return response()->json(['phone' => $reveal->reveal($request->customer(), $request)])
            ->header('Cache-Control', 'no-store, private');
    }
}
