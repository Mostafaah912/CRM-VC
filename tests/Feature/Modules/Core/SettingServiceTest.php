<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Enums\SettingKey;
use App\Modules\Core\Events\SettingChanged;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Services\SettingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

it('returns the typed default when nothing is stored', function () {
    $service = app(SettingService::class);

    expect($service->get(SettingKey::AlertsFailedJobsThreshold))->toBe(20)
        ->and($service->get(SettingKey::AlertsEnabled))->toBeTrue()
        ->and($service->get(SettingKey::AlertsReconciliationDiffPercent))->toBe(1.0);
});

it('stores and returns a value with its type preserved', function () {
    $service = app(SettingService::class);

    $service->set(SettingKey::AlertsFailedJobsThreshold, 35);
    $service->set(SettingKey::AlertsEnabled, false);
    $service->set(SettingKey::AlertsReconciliationDiffPercent, 2.5);

    expect($service->get(SettingKey::AlertsFailedJobsThreshold))->toBe(35)
        ->and($service->get(SettingKey::AlertsEnabled))->toBeFalse()
        ->and($service->get(SettingKey::AlertsReconciliationDiffPercent))->toBe(2.5);
});

it('updates an existing key instead of duplicating it', function () {
    $service = app(SettingService::class);

    $service->set(SettingKey::AlertsFailedJobsThreshold, 30);
    $service->set(SettingKey::AlertsFailedJobsThreshold, 40);

    expect(Setting::query()->count())->toBe(1)
        ->and($service->get(SettingKey::AlertsFailedJobsThreshold))->toBe(40);
});

it('rejects a value of the wrong type or out of range', function (SettingKey $key, mixed $value) {
    app(SettingService::class)->set($key, $value);
})->with([
    'non-integer threshold' => [SettingKey::AlertsFailedJobsThreshold, 'many'],
    'zero threshold' => [SettingKey::AlertsFailedJobsThreshold, 0],
    'non-boolean flag' => [SettingKey::AlertsEnabled, 'maybe'],
    'percent above 100' => [SettingKey::AlertsReconciliationDiffPercent, 150],
    'negative percent' => [SettingKey::AlertsReconciliationDiffPercent, -1],
])->throws(ValidationException::class);

it('does not persist a rejected value', function () {
    try {
        app(SettingService::class)->set(SettingKey::AlertsFailedJobsThreshold, 'many');
    } catch (ValidationException) {
    }

    expect(Setting::query()->count())->toBe(0);
});

it('serves reads from cache and invalidates the cache on set', function () {
    $service = app(SettingService::class);
    $service->set(SettingKey::AlertsFailedJobsThreshold, 25);

    expect($service->get(SettingKey::AlertsFailedJobsThreshold))->toBe(25);

    // A write that bypasses the service must not be visible until the cache is invalidated.
    DB::table('settings')->where('key', SettingKey::AlertsFailedJobsThreshold->value)->update(['value' => json_encode(99)]);
    expect($service->get(SettingKey::AlertsFailedJobsThreshold))->toBe(25);

    $service->set(SettingKey::AlertsFailedJobsThreshold, 50);
    expect($service->get(SettingKey::AlertsFailedJobsThreshold))->toBe(50);
});

it('records who changed a setting', function () {
    $user = User::factory()->create();

    app(SettingService::class)->set(SettingKey::AlertsFailedJobsThreshold, 30, $user);

    expect(Setting::query()->firstOrFail()->updated_by)->toBe($user->id);
});

it('audits a change with the before and after values', function () {
    $user = User::factory()->create();
    $service = app(SettingService::class);

    $service->set(SettingKey::AlertsFailedJobsThreshold, 30, $user);
    $service->set(SettingKey::AlertsFailedJobsThreshold, 40, $user);

    $log = AuditLog::query()->where('action', 'setting.updated')->latest('id')->firstOrFail();

    expect($log->user_id)->toBe($user->id)
        ->and($log->before)->toBe(['key' => 'alerts.failed_jobs_threshold', 'value' => 30])
        ->and($log->after)->toBe(['key' => 'alerts.failed_jobs_threshold', 'value' => 40]);
});

it('does not audit or fire an event when the value is unchanged', function () {
    $service = app(SettingService::class);
    $service->set(SettingKey::AlertsFailedJobsThreshold, 30);

    Event::fake([SettingChanged::class]);
    $before = AuditLog::query()->count();

    $service->set(SettingKey::AlertsFailedJobsThreshold, 30);

    expect(AuditLog::query()->count())->toBe($before);
    Event::assertNotDispatched(SettingChanged::class);
});

it('dispatches SettingChanged when a value changes', function () {
    Event::fake([SettingChanged::class]);

    app(SettingService::class)->set(SettingKey::AlertsEnabled, false);

    Event::assertDispatched(SettingChanged::class, fn (SettingChanged $e) => $e->key === SettingKey::AlertsEnabled);
});

it('falls back to the default after the cache is flushed and nothing is stored', function () {
    Cache::flush();

    expect(app(SettingService::class)->get(SettingKey::AlertsConsecutiveSyncFailures))->toBe(2);
});
