<?php

namespace App\Providers;

use App\Modules\Ai\Anomaly\AnomalyDetector;
use App\Modules\Ai\Events\AiBudgetExceeded;
use App\Modules\Ai\Events\AiBudgetWarning;
use App\Modules\Ai\Services\AiProvider;
use App\Modules\Ai\Services\AiProviderFactory;
use App\Modules\Ai\Services\AiUsageService;
use App\Modules\Ai\Services\AnomalySource;
use App\Modules\Ai\Services\BudgetedAiProvider;
use App\Modules\Ai\Services\BudgetGuard;
use App\Modules\Metrics\Events\MetricsRecomputed;
use App\Modules\Metrics\Listeners\MarkCustomerMetricsDirty;
use App\Modules\Orders\Events\OrderSynced;
use App\Modules\Segments\Listeners\RebuildSegmentsAfterMetricsRecomputed;
use App\Modules\Sync\Services\HttpWooClient;
use App\Modules\Sync\Services\RedisTokenBucket;
use App\Modules\Sync\Services\WooClient;
use App\Support\AiBudgetAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

        // P7-05: get_anomalies reads the real, pure-statistics detector.
        $this->app->bind(AnomalySource::class, AnomalyDetector::class);

        // Lazy for the same reason as WooClient: no AI key must never break app boot. Always wrapped in BudgetedAiProvider (P7-03): no un-budgeted path.
        $this->app->bind(AiProvider::class, fn (Application $app): AiProvider => new BudgetedAiProvider(
            $app->make(AiProviderFactory::class)->make(),
            $app->make(BudgetGuard::class),
            $app->make(AiUsageService::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // P4-07: this Laravel skeleton ships no EventServiceProvider (no listener has needed registering
        // before this one, and MarkCustomerMetricsDirty lives under app/Modules, outside the default
        // app/Listeners auto-discovery path) — Event::listen() here is the idiomatic Laravel 11+ alternative.
        Event::listen(OrderSynced::class, MarkCustomerMetricsDirty::class);

        // P5-08: Segments reacts to Metrics only through this public Event, never a direct call.
        Event::listen(MetricsRecomputed::class, RebuildSegmentsAfterMetricsRecomputed::class);

        // P7-03: Ai raises budget events; the alert itself (a Core concern) is raised outside the module.
        Event::listen(AiBudgetWarning::class, [AiBudgetAlerts::class, 'onWarning']);
        Event::listen(AiBudgetExceeded::class, [AiBudgetAlerts::class, 'onExceeded']);
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
