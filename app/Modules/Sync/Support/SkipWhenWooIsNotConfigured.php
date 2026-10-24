<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

use App\Modules\Sync\Exceptions\WooConfigurationException;
use App\Modules\Sync\Services\WooClient;
use Closure;
use Illuminate\Contracts\Container\Container;

/**
 * Job middleware for every job that talks to WooCommerce (SyncEntityJob, CatalogSyncJob, ReconcileRecentMonthsJob).
 *
 * A box with NO Woo connection at all — WOO_BASE_URL, WOO_CONSUMER_KEY and WOO_CONSUMER_SECRET all empty, as on a fresh staging
 * server — has nothing to sync. Without this, the scheduler's 15-minute poll and the nightly chain failed at dependency
 * injection every time (a failed_jobs row each, then the failed-jobs alert); now the job ends quietly and, inside the nightly
 * chain, the chain simply continues.
 *
 * It only covers "nothing configured". A half-configured or wrong connection (only a key, an http:// URL, ...) is a real
 * mistake and still throws, so it is never hidden. The probe is "can the container build a WooClient", the same validation the
 * client itself does, so tests that bind a FakeWooClient are unaffected. The job class holds no logic (CLAUDE.md §1/§5).
 */
final class SkipWhenWooIsNotConfigured
{
    public function __construct(private readonly Container $container) {}

    public function handle(object $job, Closure $next): void
    {
        try {
            $this->container->make(WooClient::class);
        } catch (WooConfigurationException $e) {
            if ($this->connectionIsEmpty()) {
                return;
            }

            throw $e;
        }

        $next($job);
    }

    private function connectionIsEmpty(): bool
    {
        return trim((string) config('woo.base_url')) === ''
            && trim((string) config('woo.key')) === ''
            && trim((string) config('woo.secret')) === '';
    }
}
