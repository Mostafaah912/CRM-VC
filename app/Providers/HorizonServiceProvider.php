<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Core\Enums\RoleName;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // P8-01: the parent lets every request through in `local`; Horizon is Owner-only in every environment.
        Horizon::auth(fn ($request): bool => Gate::forUser($request->user())->allows('viewHorizon'));

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user?->hasRole(RoleName::Owner) ?? false);
    }
}
