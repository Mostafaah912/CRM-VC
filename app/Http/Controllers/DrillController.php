<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DrillRequest;
use App\Modules\Analytics\Services\DrillService;
use Illuminate\Http\JsonResponse;

/**
 * P6-07: the uniform `GET /internal/drill/{widget}` PRD §18 boxes literally — one Service call, one
 * JSON response. Behind `dashboard,view`, the same gate as the Dashboard page itself.
 */
final class DrillController extends Controller
{
    public function __invoke(DrillRequest $request, DrillService $drill): JsonResponse
    {
        $result = $drill->rows($request->widget(), $request->period(), $request->widgetParams());

        if ($result === null) {
            abort(404, 'ویجت درخواست‌شده شناخته‌شده نیست.');
        }

        return response()->json([
            'columns' => $result->columns,
            'rows' => $result->rows,
            'truncated' => $result->truncated,
        ]);
    }
}
