<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Modules\Sync\Services\SyncHealthService;
use Inertia\Inertia;
use Inertia\Response;

class HealthController extends Controller
{
    public function __invoke(SyncHealthService $health): Response
    {
        return Inertia::render('system/health', $health->snapshot()->toArray());
    }
}
