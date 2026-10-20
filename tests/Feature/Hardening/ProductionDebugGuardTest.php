<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;

it('refuses to boot in production with APP_DEBUG=true', function () {
    $this->app['env'] = 'production';
    config(['app.debug' => true]);

    (new AppServiceProvider($this->app))->boot();
})->throws(RuntimeException::class, 'APP_DEBUG must be false in production');

it('boots in production with APP_DEBUG=false', function () {
    $this->app['env'] = 'production';
    config(['app.debug' => false]);

    (new AppServiceProvider($this->app))->boot();

    expect(true)->toBeTrue();
});

it('allows APP_DEBUG=true outside production', function () {
    config(['app.debug' => true]);

    (new AppServiceProvider($this->app))->boot();

    expect(true)->toBeTrue();
});
