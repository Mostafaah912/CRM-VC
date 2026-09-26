<?php

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Segments\Exceptions\RuleValidationException;
use App\Modules\Segments\Exceptions\SegmentException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // P2-09: machine-to-machine routes live outside the `web` group (no session, cookies or CSRF).
            Route::group([], __DIR__.'/../routes/webhooks.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'permission' => EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // P5-05/06: a Rule Builder preview, or a segment create/update/delete, refused for a
        // recoverable reason (rule failed validation, an is_system segment was targeted, or Postgres
        // cancelled a preview on statement_timeout) is always a clear Persian message, never a bare
        // 500 — kept here, not in any Controller, so every Segments controller stays a plain validate
        // -> one Service call -> response (CLAUDE.md §1). JSON requests (the preview endpoint) get a
        // plain {message}; everything else (the create/edit/destroy forms, P5-06 — a real Inertia
        // visit is not `expectsJson()` either, same as Laravel's own ValidationException) gets a
        // normal redirect-back with a field error, matching a FormRequest validation failure's shape.
        $exceptions->render(function (SegmentException|RuleValidationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            $field = $e instanceof SegmentException && $e->reason === SegmentException::NAME_TAKEN
                ? 'name'
                : ($e instanceof RuleValidationException ? 'rule' : 'segment');

            return back()->withErrors([$field => $e->getMessage()]);
        });
    })->create();
