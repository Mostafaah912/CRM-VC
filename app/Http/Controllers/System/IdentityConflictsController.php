<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Services\IdentityConflictService;
use Inertia\Inertia;
use Inertia\Response;

class IdentityConflictsController extends Controller
{
    public function __invoke(IdentityConflictService $conflicts): Response
    {
        return Inertia::render('system/identity-conflicts', [
            'conflicts' => $conflicts->paginate(),
        ]);
    }
}
