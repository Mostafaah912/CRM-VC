<?php

declare(strict_types=1);

use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Exceptions\WooConfigurationException;
use App\Modules\Sync\Jobs\CatalogSyncJob;
use App\Modules\Sync\Jobs\ReconcileRecentMonthsJob;
use App\Modules\Sync\Jobs\SyncEntityJob;
use App\Modules\Sync\Models\SyncJob;
use App\Modules\Sync\Services\WooClient;
use App\Modules\Sync\Support\SkipWhenWooIsNotConfigured;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| A box with no Woo connection at all (a fresh staging server: WOO_BASE_URL/KEY/SECRET empty) must not fail its sync jobs
| every 15 minutes. The jobs end quietly; anything half-configured still fails loudly. phpunit.xml uses the `sync` queue
| driver, so dispatching runs the job — middleware included — inline.
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
    Http::preventStrayRequests();
    app()->forgetInstance(WooClient::class); // the real binding: HttpWooClient, built from config('woo.*')
});

function wooConfig(?string $url, ?string $key, ?string $secret): void
{
    config(['woo.base_url' => $url, 'woo.key' => $key, 'woo.secret' => $secret]);
}

it('ends the orders sync, the catalog sync and the reconcile step quietly when WOO_* is empty', function (?string $empty) {
    wooConfig($empty, $empty, $empty);
    $failedBefore = DB::table('failed_jobs')->count();

    SyncEntityJob::dispatch(SyncEntity::Orders);
    CatalogSyncJob::dispatch(failSoft: true);
    ReconcileRecentMonthsJob::dispatch(2);

    expect(SyncJob::query()->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe($failedBefore);
})->with(['null' => [null], 'empty string' => ['']]);

it('does not hide a half-configured connection: a key without a URL still fails loudly', function () {
    wooConfig('', 'ck_something', '');

    expect(fn () => SyncEntityJob::dispatchSync(SyncEntity::Orders))->toThrow(WooConfigurationException::class);
});

it('does not hide a wrong connection: an http:// URL still fails loudly', function () {
    wooConfig('http://shop.example.test', 'ck_x', 'cs_x');

    expect(fn () => CatalogSyncJob::dispatchSync())->toThrow(WooConfigurationException::class);
});

it('lets a job run when a client can be built (a bound client, as every other sync test does)', function () {
    wooConfig(null, null, null);
    app()->instance(WooClient::class, Mockery::mock(WooClient::class));
    $ran = false;

    (new SkipWhenWooIsNotConfigured(app()))->handle(new stdClass, function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeTrue();
});

it('is registered as middleware on every job that talks to Woo', function () {
    foreach ([new SyncEntityJob(SyncEntity::Orders), new CatalogSyncJob, new ReconcileRecentMonthsJob(2)] as $job) {
        expect(collect($job->middleware())->contains(fn ($m) => $m instanceof SkipWhenWooIsNotConfigured))->toBeTrue($job::class);
    }
});
