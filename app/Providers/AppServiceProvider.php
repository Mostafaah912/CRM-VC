<?php

namespace App\Providers;

use App\Modules\Sync\Services\HttpWooClient;
use App\Modules\Sync\Services\RedisTokenBucket;
use App\Modules\Sync\Services\WooClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(RedisTokenBucket::class, fn (): RedisTokenBucket => new RedisTokenBucket(
            (int) config('woo.rate_limit_per_minute'),
        ));

        // Bound, not singleton, and resolved lazily: a store without credentials must not break app boot,
        // and it fails with a WooConfigurationException the moment a client is actually requested.
        $this->app->bind(WooClient::class, fn (Application $app): WooClient => new HttpWooClient(
            baseUrl: (string) config('woo.base_url'),
            key: (string) config('woo.key'),
            secret: (string) config('woo.secret'),
            version: (string) config('woo.version'),
            timeout: (int) config('woo.timeout'),
            perPage: (int) config('woo.per_page'),
            maxRetries: (int) config('woo.max_retries'),
            backoffSeconds: array_map(intval(...), (array) config('woo.retry_backoff_seconds')),
            rateLimiter: $app->make(RedisTokenBucket::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
