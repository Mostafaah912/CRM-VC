<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Core\Services\AuditService;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function index(): Response
    {
        return Inertia::render('audit/index', [
            'logs' => $this->auditService->paginate(),
        ]);
    }
}
