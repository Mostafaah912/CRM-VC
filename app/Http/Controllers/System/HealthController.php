<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Support\SystemHealthPage;
use Inertia\Inertia;
use Inertia\Response;

class HealthController extends Controller
{
    public function __invoke(SystemHealthPage $page): Response
    {
        return Inertia::render('system/health', $page->props());
    }
}
