<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Core\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level enforcement of PermissionService. This is a real security
 * boundary (unlike React's can(), which is UX only) — see CLAUDE.md §6.
 */
class EnsurePermission
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function handle(Request $request, Closure $next, string $module, string $action): Response
    {
        $user = $request->user();

        abort_unless(
            $user && $this->permissions->allows($user, $module, $action),
            403,
        );

        return $next($request);
    }
}
