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

    /**
     * `permission:segments,create` requires that one action. `permission:segments,create,edit` requires
     * ANY of them (OR) — each action is still independently resolved via deny>allow>role>default-deny,
     * so a deny override on one action never leaks approval from another.
     */
    public function handle(Request $request, Closure $next, string $module, string ...$actions): Response
    {
        $user = $request->user();

        $allowed = $user !== null && collect($actions)->contains(
            fn (string $action): bool => $this->permissions->allows($user, $module, $action),
        );

        abort_unless($allowed, 403);

        return $next($request);
    }
}
