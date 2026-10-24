<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Core\Services\TwoFactorPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * P8-01 / T1: an Owner/Manager without a confirmed second factor can only reach the pages needed to
 * set one up (and to sign out). Everything else redirects to the security settings page.
 * A real boundary, not UX: it runs on the whole `web` group, so no route can forget it.
 */
final class RequireTwoFactor
{
    /** Routes an unenrolled user may still use. */
    private const ALLOWED_ROUTES = [
        'security.edit',
        'two-factor.*',
        'password.confirm',
        'password.confirmation',
        'password.confirm.store',
        'passkey.confirm-options',
        'passkey.confirm',
        'logout',
    ];

    public function __construct(private readonly TwoFactorPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->policy->mustEnrol($user) || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'ورود دومرحله‌ای برای این نقش اجباری است.');
        }

        return redirect()->route('security.edit')
            ->with('status', 'two-factor-required');
    }
}
