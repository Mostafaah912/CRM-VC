<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\SyncLogsRequest;
use App\Modules\Sync\Services\SyncRunLogService;
use Inertia\Inertia;
use Inertia\Response;

class SyncLogsController extends Controller
{
    public function __invoke(SyncLogsRequest $request, SyncRunLogService $runs): Response
    {
        return Inertia::render('system/sync-logs', [
            'runs' => $runs->paginate($request->statusFilter(), $request->entityFilter()),
            'filters' => [
                'status' => $request->statusFilter()?->value,
                'entity' => $request->entityFilter()?->value,
            ],
            'options' => $runs->filterOptions(),
        ]);
    }
}
