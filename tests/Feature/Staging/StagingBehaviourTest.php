<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Sync\Enums\WebhookVerdict;
use App\Modules\Sync\Services\WooWebhookVerifier;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\EnvConfig;

/* P8-03 — what changes when APP_ENV=staging. */

// ------------------------------------------------------------------ hm:staging:seed

it('refuses to seed outside staging (the test environment)', function () {
    expect(Artisan::call('hm:staging:seed'))->toBe(1)
        ->and(Artisan::output())->toContain('only runs when APP_ENV=staging')
        ->and(Customer::query()->count())->toBe(0);
});

it('refuses to seed in production', function () {
    app()['env'] = 'production';
    config(['app.debug' => false]);

    expect(Artisan::call('hm:staging:seed'))->toBe(1)
        ->and(Customer::query()->count())->toBe(0);
});

it('seeds the 50 deterministic demo customers in staging', function () {
    app()['env'] = 'staging';

    expect(Artisan::call('hm:staging:seed'))->toBe(0)
        ->and(Customer::query()->count())->toBe(50);
});

// ------------------------------------------------------------------ STAGING bar

it('shares staging=true (the bar) and noindex only in staging', function () {
    $this->get(route('login'))
        ->assertHeaderMissing('X-Robots-Tag')
        ->assertInertia(fn (Assert $page) => $page->where('staging', false));

    app()['env'] = 'staging';
    config(['app.debug' => false]);

    $this->get(route('login'))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn (Assert $page) => $page->where('staging', true));
});

it('shows no STAGING flag in production', function () {
    app()['env'] = 'production';
    config(['app.debug' => false]);

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('staging', false));
});

it('has the bar component wired into both app shells', function () {
    $shell = (string) file_get_contents(resource_path('js/components/app-shell.tsx'));
    $bar = (string) file_get_contents(resource_path('js/components/staging-banner.tsx'));

    expect(substr_count($shell, '<StagingBanner />'))->toBe(2)
        ->and($bar)->toContain('STAGING')->toContain('staging !== true');
});

// ------------------------------------------------------------------ webhooks

it('rejects every webhook when woo.webhook_enabled is false, even a correctly signed one', function () {
    config(['woo.webhook_secret' => 'staging-secret', 'woo.webhook_allowed_ips' => '', 'woo.webhook_enabled' => false]);
    $body = '{"id":1}';
    $signature = base64_encode(hash_hmac('sha256', $body, 'staging-secret', true));

    expect(app(WooWebhookVerifier::class)->verify($body, $signature, '127.0.0.1'))->toBe(WebhookVerdict::Disabled);

    $this->call('POST', '/webhooks/woo', [], [], [], ['HTTP_X_WC_WEBHOOK_SIGNATURE' => $signature, 'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.updated', 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertStatus(401);
});

it('still accepts a correctly signed webhook when enabled (a staging with its own secret)', function () {
    config(['woo.webhook_secret' => 'own-secret', 'woo.webhook_allowed_ips' => '', 'woo.webhook_enabled' => true]);
    $body = '{"id":1}';

    expect(app(WooWebhookVerifier::class)->verify($body, base64_encode(hash_hmac('sha256', $body, 'own-secret', true)), '127.0.0.1'))
        ->toBe(WebhookVerdict::Authentic);
});

it('defaults webhooks OFF in staging, ON elsewhere, and lets staging opt in explicitly', function () {
    $enabled = fn (array $env) => EnvConfig::load('woo', $env)['webhook_enabled'];

    expect($enabled(['APP_ENV' => 'staging', 'WOO_WEBHOOK_ENABLED' => null]))->toBeFalse()
        ->and($enabled(['APP_ENV' => 'production', 'WOO_WEBHOOK_ENABLED' => null]))->toBeTrue()
        ->and($enabled(['APP_ENV' => 'staging', 'WOO_WEBHOOK_ENABLED' => 'true']))->toBeTrue()
        ->and($enabled(['APP_ENV' => 'production', 'WOO_WEBHOOK_ENABLED' => 'false']))->toBeFalse();
});

// ------------------------------------------------------------------ AI

it('keeps AI off in staging unless AI_ALLOW_IN_STAGING is also set', function () {
    $enabled = fn (array $env) => EnvConfig::load('ai', $env)['enabled'];

    expect($enabled(['APP_ENV' => 'staging', 'AI_ENABLED' => 'true', 'AI_ALLOW_IN_STAGING' => null]))->toBeFalse()
        ->and($enabled(['APP_ENV' => 'staging', 'AI_ENABLED' => 'true', 'AI_ALLOW_IN_STAGING' => 'true']))->toBeTrue()
        ->and($enabled(['APP_ENV' => 'production', 'AI_ENABLED' => 'true', 'AI_ALLOW_IN_STAGING' => null]))->toBeTrue()
        ->and($enabled(['APP_ENV' => 'production', 'AI_ENABLED' => null, 'AI_ALLOW_IN_STAGING' => null]))->toBeFalse();
});

// ------------------------------------------------------------------ debug + the env template

it('refuses to boot staging with APP_DEBUG=true', function () {
    app()['env'] = 'staging';
    config(['app.debug' => true]);

    (new AppServiceProvider($this->app))->boot();
})->throws(RuntimeException::class, 'APP_DEBUG must be false');

it('ships a staging env template with the safe values and no secret', function () {
    $lines = file(base_path('.env.staging.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];

    foreach ($lines as $line) {
        if (! str_starts_with($line, '#') && str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $env[$k] = $v;
        }
    }

    expect($env)->toMatchArray([
        'APP_ENV' => 'staging', 'APP_DEBUG' => 'false', 'BACKUP_ENABLED' => 'false',
        'WOO_WEBHOOK_ENABLED' => 'false', 'AI_ENABLED' => 'false', 'AI_ALLOW_IN_STAGING' => 'false',
        'MAIL_MAILER' => 'log', 'DB_DATABASE' => 'heymode_staging',
    ]);

    foreach ($env as $key => $value) {
        if (preg_match('/(SECRET|PASSWORD|API_KEY|CONSUMER_KEY|APP_KEY|S3_KEY)$/', $key) === 1) {
            expect($value)->toBe('', "{$key} must be empty in the template");
        }
    }
});
